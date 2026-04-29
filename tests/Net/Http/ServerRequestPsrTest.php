<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

final class ServerRequestPsrTest extends TestCase
{
    public function testRequestImplementsServerRequestInterface(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/path?a=1'),
            headers: ['Content-Type' => 'application/json'],
            body: BodyStream::fromString('{"ok":true}'),
            queryParams: ['a' => '1'],
            parsedBody: ['ok' => true],
            cookieParams: ['sid' => 'abc'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/path?a=1', $request->getRequestTarget());
        self::assertSame(['a' => '1'], $request->getQueryParams());
        self::assertSame(['ok' => true], $request->getParsedBody());
        self::assertSame(['sid' => 'abc'], $request->getCookieParams());
        self::assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testWithMethodsAreImmutable(): void
    {
        $request = new Request(method: 'GET', uri: new Uri('http://example.com/old'));
        $changed = $request->withMethod('PUT')
            ->withUri(new Uri('https://api.example.com/new?x=1'))
            ->withAttribute('route', 'home');

        self::assertSame('GET', $request->getMethod());
        self::assertSame('PUT', $changed->getMethod());
        self::assertSame('/new?x=1', $changed->getRequestTarget());
        self::assertSame(null, $request->getAttribute('route'));
        self::assertSame('home', $changed->getAttribute('route'));
    }

    public function testLegacyReadonlyArraysMirrorPsrState(): void
    {
        $request = new Request(
            method: 'GET',
            uri: new Uri('http://example.com/?a=1'),
            queryParams: ['a' => '1'],
            parsedBody: ['b' => '2'],
            cookieParams: ['c' => '3'],
            uploadedFiles: ['file' => []],
            serverParams: ['REQUEST_METHOD' => 'GET'],
            body: BodyStream::fromString('body'),
        );

        self::assertSame(['a' => '1'], $request->GET);
        self::assertSame(['b' => '2'], $request->POST);
        self::assertSame(['c' => '3'], $request->COOKIE);
        self::assertSame(['file' => []], $request->FILES);
        self::assertSame(['REQUEST_METHOD' => 'GET'], $request->SERVER);
        self::assertSame('body', $request->CONTENT);
    }
}
