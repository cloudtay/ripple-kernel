<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Client\Client;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Uri;
use ReflectionMethod;
use Throwable;

use function array_shift;
use function file_get_contents;
use function gzencode;
use function fclose;
use function fopen;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;
use function count;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ClientTest extends TestCase
{
    public function testSendRequestSerializesRequestAndReturnsParsedResponse(): void
    {
        $client = new Client([
            'connector' => static fn (): object => new class () {
                public string $written = '';
                public function writeAll(string $bytes): int
                {
                    $this->written .= $bytes;
                    return strlen($bytes);
                }
                public function read(int $length): string
                {
                    return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                }
                public function close(): void
                {
                }
            },
        ]);

        $response = $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string)$response->getBody());
    }

    public function testConvenienceGetBuildsRequest(): void
    {
        $client = new Client([
            'sender' => static fn (Request $request): Response => Response::text($request->getMethod() . ' ' . $request->getRequestTarget()),
        ]);

        $response = $client->get('http://example.com/path?q=1');

        self::assertSame('GET /path?q=1', (string)$response->getBody());
    }

    public function testClientOptionsNormalizeDefaultsAndDeadline(): void
    {
        $defaults = \Ripple\Net\Http\Client\ClientOptions::fromArray([]);

        self::assertSame(10.0, $defaults->connectTimeout());
        self::assertSame(10.0, $defaults->writeTimeout());
        self::assertSame(30.0, $defaults->readTimeout());
        self::assertSame(0.0, $defaults->requestTimeout());
        self::assertSame(65536, $defaults->maxHeaderBytes());
        self::assertSame(0, $defaults->maxBodyBytes());
        self::assertTrue($defaults->decodeContent());
        self::assertNull($defaults->remainingRequestTime());

        $withDeadline = \Ripple\Net\Http\Client\ClientOptions::fromArray([
            'request_timeout' => 1.5,
        ]);

        self::assertSame(1.5, $withDeadline->requestTimeout());
        self::assertGreaterThan(0.0, $withDeadline->remainingRequestTime());
        self::assertLessThanOrEqual(1.5, $withDeadline->connectTimeout());
        self::assertLessThanOrEqual(1.5, $withDeadline->writeTimeout());
        self::assertLessThanOrEqual(1.5, $withDeadline->readTimeout());
    }

    public function testTimeoutExceptionIsNetworkException(): void
    {
        $request = new Request(method: 'GET', uri: new Uri('http://example.com/'));
        $exception = new \Ripple\Net\Http\Exception\TimeoutException('Read timeout', $request);

        self::assertInstanceOf(\Psr\Http\Client\NetworkExceptionInterface::class, $exception);
        self::assertSame($request, $exception->getRequest());
    }

    public function testSendRequestUsesWriteTimeout(): void
    {
        $capturedTimeout = null;
        $client = new Client([
            'write_timeout' => 0.25,
            'connector' => static function () use (&$capturedTimeout): object {
                return new class ($capturedTimeout) {
                    private mixed $capturedTimeout;

                    public function __construct(mixed &$capturedTimeout)
                    {
                        $this->capturedTimeout = &$capturedTimeout;
                    }

                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        $this->capturedTimeout = $timeout;
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                    }

                    public function close(): void
                    {
                    }
                };
            },
        ]);

        $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));

        self::assertSame(0.25, $capturedTimeout);
    }

    public function testSendRequestThrowsTimeoutWhenRequestDeadlineExpired(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\TimeoutException::class);

        $client = new Client([
            'request_timeout' => 0.001,
            'connector' => static fn (): object => new class () {
                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    usleep(5000);
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                }

                public function close(): void
                {
                }
            },
        ]);

        $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));
    }

    public function testSendRequestDecodesGzipWhenEnabled(): void
    {
        $payload = gzencode('hello');
        $client = new Client([
            'connector' => static fn (): object => new class ($payload) {
                public function __construct(private readonly string $payload)
                {
                }

                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    return "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen($this->payload) . "\r\n\r\n" . $this->payload;
                }

                public function close(): void
                {
                }
            },
        ]);

        $response = $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));

        self::assertSame('hello', (string)$response->getBody());
        self::assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testReadTimeoutUsesStreamWatcher(): void
    {
        $thrown = null;

        [$server, $clientSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($clientSocket, false);

        $stream = new \Ripple\Stream($clientSocket);
        $client = new Client([
            'read_timeout' => 0.05,
            'connector' => static fn () => $stream,
        ]);

        \Co\go(function () use ($client, &$thrown): void {
            try {
                $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));
            } catch (Throwable $exception) {
                $thrown = $exception;
            }
        });

        \Co\go(static function () use ($server): void {
            \Ripple\Time::sleep(0.2);
            fclose($server);
        });

        \Co\wait();

        self::assertInstanceOf(\Ripple\Net\Http\Exception\TimeoutException::class, $thrown);
    }

    public function testRequestBuilderCreatesMultipartRequestWithLength(): void
    {
        $builder = new \Ripple\Net\Http\Client\RequestBuilder();

        [$request, $transfer] = $builder->build('POST', 'http://example.com/upload', [
            'multipart' => [
                ['name' => 'file', 'contents' => BodyStream::fromString('abc'), 'filename' => 'a.txt'],
            ],
        ]);

        self::assertStringContainsString('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertGreaterThan(0, (int)$request->getHeaderLine('Content-Length'));
        self::assertSame((int)$request->getHeaderLine('Content-Length'), $transfer->uploadTotal());
    }

    public function testRequestBuilderRejectsConflictingBodyOptions(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\RequestException::class);

        (new \Ripple\Net\Http\Client\RequestBuilder())->build('POST', 'http://example.com/', [
            'json' => ['a' => 1],
            'body' => 'x',
        ]);
    }

    public function testClientWritesRequestBodyInChunksAndReportsUploadProgress(): void
    {
        $written = [];
        $progress = [];
        $client = new Client([
            'upload_chunk_size' => 2,
            'connector' => static function () use (&$written): object {
                return new class ($written) {
                    public function __construct(private array &$written)
                    {
                    }

                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        $this->written[] = $bytes;
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                    }

                    public function eof(): bool
                    {
                        return false;
                    }

                    public function close(): void
                    {
                    }
                };
            },
        ]);

        $client->post('http://example.com/', [
            'body' => 'abcd',
            'progress' => static function (int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded) use (&$progress): void {
                $progress[] = [$downloadTotal, $downloaded, $uploadTotal, $uploaded];
            },
        ]);

        self::assertStringContainsString("Content-Length: 4\r\n", $written[0]);
        self::assertSame('ab', $written[1]);
        self::assertSame('cd', $written[2]);
        self::assertSame([0, 0, 4, 4], $progress[count($progress) - 1]);
    }

    public function testClientStreamsFixedLengthResponseToSink(): void
    {
        $sink = tempnam(sys_get_temp_dir(), 'ripple_sink_');
        $progress = [];

        try {
            $client = new Client([
                'connector' => static fn (): object => new class () {
                    private array $chunks = [
                        "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nab",
                        'cd',
                        'ef',
                    ];

                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        return array_shift($this->chunks) ?? '';
                    }

                    public function close(): void
                    {
                    }
                },
            ]);

            $response = $client->get('http://example.com/file', [
                'sink' => $sink,
                'progress' => static function (int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded) use (&$progress): void {
                    $progress[] = [$downloadTotal, $downloaded, $uploadTotal, $uploaded];
                },
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('abcdef', file_get_contents($sink));
            self::assertSame([6, 6, 0, 0], $progress[count($progress) - 1]);
        } finally {
            unlink($sink);
        }
    }

    public function testClientOpensResourceAndPathSinksAsStreams(): void
    {
        $client = new Client();
        $openSink = new ReflectionMethod($client, 'openSink');
        $openSink->setAccessible(true);

        $resource = fopen('php://temp', 'w+');
        self::assertIsResource($resource);
        $path = tempnam(sys_get_temp_dir(), 'ripple_sink_');

        try {
            self::assertInstanceOf(StreamInterface::class, $openSink->invoke($client, $resource));
            self::assertInstanceOf(StreamInterface::class, $openSink->invoke($client, $path));
        } finally {
            fclose($resource);
            unlink($path);
        }
    }

    public function testClientStreamsChunkedResponseToSink(): void
    {
        $sink = tempnam(sys_get_temp_dir(), 'ripple_sink_');

        try {
            $client = new Client([
                'connector' => static fn (): object => new class () {
                    private array $chunks = [
                        "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2;foo=bar\r\nab\r\n",
                        "4\r\ncdef\r\n0\r\nX-Trailer: yes\r\n\r\n",
                    ];

                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        return array_shift($this->chunks) ?? '';
                    }

                    public function close(): void
                    {
                    }
                },
            ]);

            $response = $client->get('http://example.com/file', [
                'sink' => $sink,
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($response->hasHeader('Transfer-Encoding'));
            self::assertSame('6', $response->getHeaderLine('Content-Length'));
            self::assertSame('abcdef', file_get_contents($sink));
        } finally {
            unlink($sink);
        }
    }

    public function testClientRejectsSinkResponseWithUnknownLength(): void
    {
        $sink = tempnam(sys_get_temp_dir(), 'ripple_sink_');

        try {
            $client = new Client([
                'connector' => static fn (): object => new class () {
                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        return "HTTP/1.1 200 OK\r\n\r\nbody";
                    }

                    public function close(): void
                    {
                    }
                },
            ]);

            $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);
            $client->get('http://example.com/file', [
                'sink' => $sink,
            ]);
        } finally {
            unlink($sink);
        }
    }

    public function testClientReturnsNetworkStreamWhenStreamOptionIsEnabled(): void
    {
        $closed = false;
        $reads = 0;
        $client = new Client([
            'connector' => static function () use (&$closed, &$reads): object {
                return new class ($closed, $reads) {
                    private array $chunks = [
                        "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\nab",
                        'cd',
                        'ef',
                    ];

                    public function __construct(private bool &$closed, private int &$reads)
                    {
                    }

                    public function writeAll(string $bytes, ?float $timeout = null): int
                    {
                        return strlen($bytes);
                    }

                    public function read(int $length): string
                    {
                        $this->reads++;
                        return array_shift($this->chunks) ?? '';
                    }

                    public function close(): void
                    {
                        $this->closed = true;
                    }
                };
            },
        ]);

        $response = $client->get('http://example.com/file', [
            'stream' => true,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($closed);
        self::assertSame(1, $reads);
        self::assertSame('abcdef', $response->getBody()->getContents());
        self::assertFalse($closed);

        $response->getBody()->close();
        self::assertTrue($closed);
    }

    public function testClientReturnsDecodedChunkedNetworkStream(): void
    {
        $client = new Client([
            'connector' => static fn (): object => new class () {
                private array $chunks = [
                    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nab\r\n",
                    "4\r\ncdef\r\n0\r\n\r\n",
                ];

                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    return array_shift($this->chunks) ?? '';
                }

                public function close(): void
                {
                }
            },
        ]);

        $response = $client->get('http://example.com/file', [
            'stream' => true,
        ]);

        self::assertFalse($response->hasHeader('Transfer-Encoding'));
        self::assertSame('abcdef', $response->getBody()->getContents());
    }
}
