<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

use function strcasecmp;
use function implode;

final class ProtocolTest extends TestCase
{
    public function testSerializesRequestInterface(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/api?q=1'),
            headers: ['Content-Type' => 'text/plain'],
            body: BodyStream::fromString('hello'),
        );

        $bytes = (new RequestSerializer())->serialize($request);

        self::assertStringStartsWith("POST /api?q=1 HTTP/1.1\r\n", $bytes);
        self::assertStringContainsString("Host: example.com\r\n", $bytes);
        self::assertStringContainsString("Content-Length: 5\r\n", $bytes);
        self::assertStringEndsWith("\r\n\r\nhello", $bytes);
    }

    public function testSerializerRejectsHeaderNameInjection(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\RequestException::class);

        $request = $this->serializerRequestWithHeaders(["Bad\r\nName" => ['value']]);

        (new RequestSerializer())->serialize($request);
    }

    public function testSerializerRejectsHeaderValueInjection(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\RequestException::class);

        $request = new Request(
            method: 'GET',
            uri: new Uri('http://example.com/'),
            headers: ['X-Test' => "ok\r\nInjected: yes"]
        );

        (new RequestSerializer())->serialize($request);
    }

    public function testSerializerRejectsContentLengthTransferEncodingConflict(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\RequestException::class);

        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/'),
            headers: [
                'Content-Length' => '4',
                'Transfer-Encoding' => 'chunked',
            ],
            body: BodyStream::fromString('test')
        );

        (new RequestSerializer())->serialize($request);
    }

    public function testSerializerAddsDefaultUserAgentAndAcceptEncoding(): void
    {
        $request = new Request(method: 'GET', uri: new Uri('http://example.com/'));

        $bytes = (new RequestSerializer())->serialize($request);

        self::assertStringContainsString("User-Agent: Ripple-Kernel\r\n", $bytes);
        self::assertStringContainsString("Accept-Encoding: gzip, deflate", $bytes);
    }

    public function testParsesFixedLengthResponse(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push("HTTP/1.1 201 Created\r\nContent-Length: 2\r\nX-Test: ok\r\n\r\nhi");

        self::assertCount(1, $responses);
        self::assertSame(201, $responses[0]->getStatusCode());
        self::assertSame('Created', $responses[0]->getReasonPhrase());
        self::assertSame('ok', $responses[0]->getHeaderLine('X-Test'));
        self::assertSame('hi', (string)$responses[0]->getBody());
    }

    public function testParsesChunkedResponse(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nhi\r\n0\r\n\r\n");

        self::assertCount(1, $responses);
        self::assertSame('hi', (string)$responses[0]->getBody());
    }

    public function testParserSkipsInformationalResponses(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push(
            "HTTP/1.1 100 Continue\r\n\r\n" .
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"
        );

        self::assertCount(1, $responses);
        self::assertSame(200, $responses[0]->getStatusCode());
        self::assertSame('ok', (string)$responses[0]->getBody());
    }

    public function testParserForcesNoBodyForHead(): void
    {
        $parser = new ResponseParser(method: 'HEAD');
        $responses = $parser->push("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");

        self::assertCount(1, $responses);
        self::assertSame('', (string)$responses[0]->getBody());
    }

    public function testParserForcesNoBodyForNoContentAndNotModified(): void
    {
        $parser = new ResponseParser();

        $responses = $parser->push(
            "HTTP/1.1 204 No Content\r\n\r\n" .
            "HTTP/1.1 304 Not Modified\r\n\r\n"
        );

        self::assertCount(2, $responses);
        self::assertSame('', (string)$responses[0]->getBody());
        self::assertSame('', (string)$responses[1]->getBody());
    }

    public function testParserAcceptsChunkExtensionsAndConsumesTrailers(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" .
            "2;foo=bar\r\nhi\r\n0\r\nX-Trailer: yes\r\n\r\n"
        );

        self::assertCount(1, $responses);
        self::assertSame('hi', (string)$responses[0]->getBody());
        self::assertFalse($responses[0]->hasHeader('Transfer-Encoding'));
    }

    public function testParserAcceptsChunkedTransferEncodingParameters(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked; level=1\r\n\r\n" .
            "2\r\nhi\r\n0\r\n\r\n"
        );

        self::assertCount(1, $responses);
        self::assertSame('hi', (string)$responses[0]->getBody());
    }

    public function testParserRejectsResponseWithBothTransferEncodingAndContentLength(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser();
        $parser->push(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 2\r\n\r\n" .
            "2\r\nhi\r\n0\r\n\r\n"
        );
    }

    public function testParserRejectsUnsupportedTransferEncoding(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser();
        $parser->push(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip, chunked\r\n\r\n" .
            "1f\r\n\x1f\x8bunsupported-transfer-coding\r\n0\r\n\r\n"
        );
    }

    public function testParserRejectsDuplicateChunkedTransferEncoding(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser();
        $parser->push(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked, chunked\r\n\r\n" .
            "2\r\nhi\r\n0\r\n\r\n"
        );
    }

    public function testParserAllowsRepeatedIdenticalContentLength(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 2\r\n\r\nok");

        self::assertCount(1, $responses);
        self::assertSame('ok', (string)$responses[0]->getBody());
    }

    public function testParserRejectsConflictingContentLength(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser();
        $parser->push("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 3\r\n\r\nok!");
    }

    public function testParserEnforcesHeaderLimit(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser(maxHeaderBytes: 16);
        $parser->push("HTTP/1.1 200 OK\r\nX-Long: abcdefghijklmnopqrstuvwxyz\r\n\r\n");
    }

    public function testParserEnforcesBodyLimit(): void
    {
        $this->expectException(\Ripple\Net\Http\Exception\ProtocolException::class);

        $parser = new ResponseParser(maxBodyBytes: 2);
        $parser->push("HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nabc");
    }

    public function testParserCompletesCloseDelimitedResponseOnEof(): void
    {
        $parser = new ResponseParser();
        self::assertSame([], $parser->push("HTTP/1.1 200 OK\r\n\r\nhello"));

        $responses = $parser->finish();

        self::assertCount(1, $responses);
        self::assertSame('hello', (string)$responses[0]->getBody());
    }

    private function serializerRequestWithHeaders(array $headers): RequestInterface
    {
        return new class ($headers) implements RequestInterface {
            public function __construct(private readonly array $headers)
            {
            }

            public function getRequestTarget(): string
            {
                return '/';
            }

            public function withRequestTarget(string $requestTarget): RequestInterface
            {
                return $this;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function withMethod(string $method): RequestInterface
            {
                return $this;
            }

            public function getUri(): UriInterface
            {
                return new Uri('http://example.com/');
            }

            public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
            {
                return $this;
            }

            public function getProtocolVersion(): string
            {
                return '1.1';
            }

            public function withProtocolVersion(string $version): RequestInterface
            {
                return $this;
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function hasHeader(string $name): bool
            {
                foreach ($this->headers as $headerName => $_) {
                    if (strcasecmp((string)$headerName, $name) === 0) {
                        return true;
                    }
                }

                return false;
            }

            public function getHeader(string $name): array
            {
                foreach ($this->headers as $headerName => $values) {
                    if (strcasecmp((string)$headerName, $name) === 0) {
                        return (array)$values;
                    }
                }

                return [];
            }

            public function getHeaderLine(string $name): string
            {
                return implode(', ', $this->getHeader($name));
            }

            public function withHeader(string $name, $value): RequestInterface
            {
                return $this;
            }

            public function withAddedHeader(string $name, $value): RequestInterface
            {
                return $this;
            }

            public function withoutHeader(string $name): RequestInterface
            {
                return $this;
            }

            public function getBody(): StreamInterface
            {
                return BodyStream::fromString('');
            }

            public function withBody(StreamInterface $body): RequestInterface
            {
                return $this;
            }
        };
    }
}
