<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

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
}
