<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
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
        self::assertSame(['name' => 'ripple'], $requests[0]['get']);
        self::assertSame([], $requests[0]['post']);
        self::assertSame(['sid' => 'abc', 'theme' => 'dark'], $requests[0]['cookies']);
        self::assertSame('127.0.0.1', $requests[0]['server']['REMOTE_ADDR']);
        self::assertSame(12345, $requests[0]['server']['REMOTE_PORT']);
        self::assertSame('/hello', $requests[0]['server']['REQUEST_URI']);
        self::assertSame('GET', $requests[0]['server']['REQUEST_METHOD']);
        self::assertSame('example.test', $requests[0]['server']['HTTP_HOST']);
        self::assertSame('close', $requests[0]['server']['HTTP_CONNECTION']);
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
        self::assertSame(['ok' => true, 'name' => 'ripple'], $requests[0]['post']);
        self::assertSame($body, $requests[0]['content']);
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
        self::assertSame([], $requests[0]['post']);
        self::assertSame('', $requests[0]['content']);
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
        self::assertSame(['a' => '1', 'b' => 'two'], $requests[0]['post']);
        self::assertSame($body, $requests[0]['content']);
    }

    public function testParsesPipelinedRequests(): void
    {
        $parser = new RequestParser();

        $requests = $parser->push(
            "GET /one HTTP/1.1\r\nHost: example.test\r\n\r\n" .
            "GET /two HTTP/1.1\r\nHost: example.test\r\n\r\n"
        );

        self::assertCount(2, $requests);
        self::assertSame('/one', $requests[0]['server']['REQUEST_URI']);
        self::assertSame('/two', $requests[1]['server']['REQUEST_URI']);
    }
}
