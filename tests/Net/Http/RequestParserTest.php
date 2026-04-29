<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Parser\FormDataParser;
use Ripple\Net\Http\Parser\RequestParser;

use function strlen;
use function class_exists;

final class RequestParserTest extends TestCase
{
    public function testMultipartParserLivesInParserNamespace(): void
    {
        self::assertTrue(class_exists(FormDataParser::class));
        self::assertFalse(class_exists('Ripple\\Net\\Http\\Upload\\FormData'));
    }

    public function testParsesGetRequestWithQueryHeadersAndCookies(): void
    {
        $parser = new RequestParser([
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => 12345,
        ]);

        $requests = $parser->push(
            "GET /hello?name=ripple HTTP/1.1\r\n" .
            "Host: example.test\r\n" .
            "Cookie: sid=abc; theme=dark\r\n" .
            "Connection: close\r\n" .
            "\r\n"
        );

        self::assertCount(1, $requests);
        self::assertInstanceOf(Request::class, $requests[0]);
        self::assertSame(['name' => 'ripple'], $requests[0]->getQueryParams());
        self::assertSame([], $requests[0]->getParsedBody());
        self::assertSame(['sid' => 'abc', 'theme' => 'dark'], $requests[0]->getCookieParams());
        self::assertSame('127.0.0.1', $requests[0]->getServerParams()['REMOTE_ADDR']);
        self::assertSame(12345, $requests[0]->getServerParams()['REMOTE_PORT']);
        self::assertSame('/hello', $requests[0]->getServerParams()['REQUEST_URI']);
        self::assertSame('GET', $requests[0]->getMethod());
        self::assertSame('example.test', $requests[0]->getHeaderLine('Host'));
        self::assertSame('close', $requests[0]->getHeaderLine('Connection'));
    }

    public function testParsesJsonPostBody(): void
    {
        $body = '{"ok":true,"name":"ripple"}';
        $parser = new RequestParser();

        $requests = $parser->push(
            "POST /json HTTP/1.1\r\n" .
            "Host: example.test\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: " . strlen($body) . "\r\n" .
            "\r\n" .
            $body
        );

        self::assertCount(1, $requests);
        self::assertSame(['ok' => true, 'name' => 'ripple'], $requests[0]->getParsedBody());
        self::assertSame($body, (string)$requests[0]->getBody());
    }

    public function testEmptyJsonPostBodyDoesNotThrow(): void
    {
        $parser = new RequestParser();

        $requests = $parser->push(
            "POST /json HTTP/1.1\r\n" .
            "Host: example.test\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: 0\r\n" .
            "\r\n"
        );

        self::assertCount(1, $requests);
        self::assertSame([], $requests[0]->getParsedBody());
        self::assertSame('', (string)$requests[0]->getBody());
    }

    public function testParsesUrlEncodedPostBody(): void
    {
        $body = 'a=1&b=two';
        $parser = new RequestParser();

        $requests = $parser->push(
            "POST /form HTTP/1.1\r\n" .
            "Host: example.test\r\n" .
            "Content-Type: application/x-www-form-urlencoded\r\n" .
            "Content-Length: " . strlen($body) . "\r\n" .
            "\r\n" .
            $body
        );

        self::assertCount(1, $requests);
        self::assertSame(['a' => '1', 'b' => 'two'], $requests[0]->getParsedBody());
        self::assertSame($body, (string)$requests[0]->getBody());
    }

    public function testParsesPipelinedRequests(): void
    {
        $parser = new RequestParser();

        $requests = $parser->push(
            "GET /one HTTP/1.1\r\nHost: example.test\r\n\r\n" .
            "GET /two HTTP/1.1\r\nHost: example.test\r\n\r\n"
        );

        self::assertCount(2, $requests);
        self::assertSame('/one', $requests[0]->getServerParams()['REQUEST_URI']);
        self::assertSame('/two', $requests[1]->getServerParams()['REQUEST_URI']);
    }
}
