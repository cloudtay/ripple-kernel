<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Response;

final class MessageHeadersTest extends TestCase
{
    public function testHeadersAreCaseInsensitiveAndImmutable(): void
    {
        $response = new Response();
        $changed = $response->withHeader('Content-Type', 'text/plain');
        $added = $changed->withAddedHeader('content-type', 'charset=utf-8');

        self::assertFalse($response->hasHeader('content-type'));
        self::assertSame(['text/plain'], $changed->getHeader('content-type'));
        self::assertSame(['text/plain', 'charset=utf-8'], $added->getHeader('CONTENT-TYPE'));
        self::assertSame('text/plain, charset=utf-8', $added->getHeaderLine('Content-Type'));
    }

    public function testBodyAndProtocolVersionAreImmutable(): void
    {
        $response = new Response();
        $body = BodyStream::fromString('payload');
        $changed = $response->withProtocolVersion('1.0')->withBody($body);

        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('1.0', $changed->getProtocolVersion());
        self::assertSame('', (string)$response->getBody());
        self::assertSame('payload', (string)$changed->getBody());
    }
}
