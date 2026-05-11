<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Client\Client;

use function array_shift;
use function count;
use function explode;
use function fclose;
use function file_get_contents;
use function filesize;
use function fopen;
use function gzencode;
use function hash;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function preg_match;
use function rewind;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function fwrite;

use const JSON_UNESCAPED_SLASHES;

final class HttpClientUsageTest extends TestCase
{
    public function testHttpbinStyleRequestOptionUsage(): void
    {
        $client = $this->httpbinClient();

        $get = $this->json($client->get('http://example.test/get?name=ripple&debug=1', [
            'headers' => ['X-Debug-Client' => 'ripple-kernel'],
        ]));
        self::assertSame('GET', $get['method']);
        self::assertSame('http://example.test/get?name=ripple&debug=1', $get['url']);
        self::assertSame('ripple-kernel', $get['headers']['X-Debug-Client']);

        $generic = $this->json($client->request('GET', 'http://example.test/anything/generic', [
            'headers' => ['X-Request-Api' => 'request-method'],
        ]));
        self::assertSame('GET', $generic['method']);
        self::assertSame('request-method', $generic['headers']['X-Request-Api']);

        $json = $this->json($client->post('http://example.test/post', [
            'json' => ['name' => 'ripple', 'enabled' => true, 'count' => 3],
        ]));
        self::assertSame('ripple', $json['json']['name']);
        self::assertSame('application/json', $json['headers']['Content-Type']);

        $form = $this->json($client->post('http://example.test/post', [
            'form_params' => ['username' => 'alice', 'role' => 'admin'],
        ]));
        self::assertSame('alice', $form['form']['username']);
        self::assertSame('admin', $form['form']['role']);

        $raw = $this->json($client->post('http://example.test/post', [
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => 'plain request body',
        ]));
        self::assertSame('plain request body', $raw['data']);

        $resource = fopen('php://temp', 'w+');
        self::assertIsResource($resource);
        fwrite($resource, 'resource request body');
        rewind($resource);
        try {
            $resourceBody = $this->json($client->post('http://example.test/post', [
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => $resource,
            ]));
        } finally {
            fclose($resource);
        }
        self::assertSame('resource request body', $resourceBody['data']);

        $streamBody = $this->json($client->post('http://example.test/post', [
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => BodyStream::fromString('psr stream request body'),
        ]));
        self::assertSame('psr stream request body', $streamBody['data']);

        $multipart = $this->json($client->post('http://example.test/post', [
            'multipart' => [
                ['name' => 'field', 'contents' => 'value'],
                ['name' => 'upload', 'filename' => 'example.txt', 'contents' => 'file contents from multipart'],
            ],
        ]));
        self::assertSame('value', $multipart['form']['field']);
        self::assertSame('file contents from multipart', $multipart['files']['upload']);

        $put = $this->json($client->put('http://example.test/put', [
            'json' => ['operation' => 'replace'],
        ]));
        self::assertSame('PUT', $put['method']);
        self::assertSame('replace', $put['json']['operation']);

        $patch = $this->json($client->patch('http://example.test/patch', [
            'json' => ['operation' => 'patch'],
        ]));
        self::assertSame('PATCH', $patch['method']);
        self::assertSame('patch', $patch['json']['operation']);

        $delete = $this->json($client->delete('http://example.test/delete'));
        self::assertSame('DELETE', $delete['method']);
        self::assertSame('http://example.test/delete', $delete['url']);
    }

    public function testHttpbinStyleGzipDecodeUsage(): void
    {
        $encoded = gzencode($this->jsonEncode(['gzipped' => true]));
        self::assertIsString($encoded);

        $client = $this->clientForResponse(
            "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen($encoded) . "\r\n\r\n" . $encoded
        );

        $response = $client->get('http://example.test/gzip');

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame(['gzipped' => true], $this->json($response));
    }

    public function testHttpbinStyleChunkedAndStreamUsage(): void
    {
        $client = $this->clientForResponse([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nalpha\r\n",
            "4\r\nbeta\r\n0\r\n\r\n",
        ]);

        $response = $client->get('http://example.test/stream/2', [
            'stream' => true,
        ]);

        self::assertFalse($response->hasHeader('Transfer-Encoding'));
        self::assertFalse($response->getBody()->isSeekable());
        self::assertSame('alpha', $response->getBody()->read(5));
        self::assertSame('beta', $response->getBody()->getContents());
    }

    public function testHttpbinStyleSinkDownloadVerifiesFileSizeHashAndProgress(): void
    {
        $bytes = "0123456789abcdef0123456789abcdef";
        $sink = tempnam(sys_get_temp_dir(), 'ripple_usage_sink_');
        self::assertIsString($sink);
        $progress = [];

        try {
            $client = $this->clientForResponse(
                "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: " . strlen($bytes) . "\r\n\r\n" . $bytes
            );

            $response = $client->get('http://example.test/bytes/' . strlen($bytes), [
                'sink' => $sink,
                'progress' => static function (int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded) use (&$progress): void {
                    $progress[] = [$downloadTotal, $downloaded, $uploadTotal, $uploaded];
                },
            ]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(strlen($bytes), filesize($sink));
            self::assertSame(hash('sha256', $bytes), hash('sha256', (string)file_get_contents($sink)));
            self::assertSame($bytes, (string)$response->getBody());
            self::assertSame([strlen($bytes), strlen($bytes), 0, 0], $progress[count($progress) - 1]);
        } finally {
            unlink($sink);
        }
    }

    public function testHttpbinStyleResponseSizeLimitUsage(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $client = new Client([
            'max_body_bytes' => 8,
            'connector' => static fn (): object => new class () {
                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    return "HTTP/1.1 200 OK\r\nContent-Length: 16\r\n\r\n0123456789abcdef";
                }

                public function close(): void
                {
                }
            },
        ]);

        $client->get('http://example.test/bytes/16');
    }

    private function httpbinClient(): Client
    {
        return new Client([
            'upload_chunk_size' => 4,
            'connector' => fn (): object => new class ($this) {
                private string $written = '';

                private bool $responded = false;

                public function __construct(private readonly HttpClientUsageTest $test)
                {
                }

                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    $this->written .= $bytes;
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    if ($this->responded) {
                        return '';
                    }
                    $this->responded = true;
                    return $this->test->httpbinResponse($this->written);
                }

                public function eof(): bool
                {
                    return $this->responded;
                }

                public function close(): void
                {
                }
            },
        ]);
    }

    private function clientForResponse(string|array $response): Client
    {
        $chunks = is_array($response) ? $response : [$response];

        return new Client([
            'connector' => static fn (): object => new class ($chunks) {
                public function __construct(private array $chunks)
                {
                }

                public function writeAll(string $bytes, ?float $timeout = null): int
                {
                    return strlen($bytes);
                }

                public function read(int $length): string
                {
                    return array_shift($this->chunks) ?? '';
                }

                public function eof(): bool
                {
                    return $this->chunks === [];
                }

                public function close(): void
                {
                }
            },
        ]);
    }

    public function httpbinResponse(string $requestBytes): string
    {
        $request = $this->parseRequest($requestBytes);
        $body = $request['body'];
        $headers = $request['headers'];
        $contentType = $headers['Content-Type'] ?? '';
        $form = [];
        $files = [];
        $json = null;

        if ($contentType === 'application/json' && $body !== '') {
            $decoded = json_decode($body, true);
            $json = is_array($decoded) ? $decoded : null;
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($body, $form);
        } elseif (str_starts_with($contentType, 'multipart/form-data; boundary=')) {
            [$form, $files] = $this->parseMultipart($body, substr($contentType, strlen('multipart/form-data; boundary=')));
        }

        $payload = [
            'method' => $request['method'],
            'url' => 'http://example.test' . $request['target'],
            'headers' => $headers,
            'args' => [],
            'data' => $body,
            'json' => $json,
            'form' => $form,
            'files' => $files,
        ];

        $encoded = $this->jsonEncode($payload);
        return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($encoded) . "\r\n\r\n" . $encoded;
    }

    /**
     * @return array{method:string,target:string,headers:array<string,string>,body:string}
     */
    private function parseRequest(string $requestBytes): array
    {
        [$head, $body] = explode("\r\n\r\n", $requestBytes, 2);
        $lines = explode("\r\n", $head);
        [$method, $target] = explode(' ', array_shift($lines), 3);
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }

        return [
            'method' => $method,
            'target' => $target,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $form = [];
        $files = [];
        foreach (explode("--{$boundary}", $body) as $part) {
            $part = trim($part, "\r\n-");
            if ($part === '' || !str_contains($part, "\r\n\r\n")) {
                continue;
            }
            [$head, $contents] = explode("\r\n\r\n", $part, 2);
            $name = null;
            $filename = null;
            foreach (explode("\r\n", $head) as $line) {
                if (preg_match('/Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]+)")?/i', $line, $matches) === 1) {
                    $name = $matches[1];
                    $filename = $matches[2] ?? null;
                }
            }
            if ($name === null) {
                continue;
            }
            if ($filename !== null) {
                $files[$name] = $contents;
            } else {
                $form[$name] = $contents;
            }
        }

        return [$form, $files];
    }

    /**
     * @return array<string,mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonEncode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        return $encoded;
    }
}
