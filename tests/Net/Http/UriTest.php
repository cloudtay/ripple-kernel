<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Ripple\Net\Http\Uri;

final class UriTest extends TestCase
{
    public function testParsesAndRendersUri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8443/api?q=1#frag');

        self::assertInstanceOf(UriInterface::class, $uri);
        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/api', $uri->getPath());
        self::assertSame('q=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
        self::assertSame('https://user:pass@example.com:8443/api?q=1#frag', (string)$uri);
    }

    public function testWithMethodsAreImmutable(): void
    {
        $uri = new Uri('http://example.com/a');
        $changed = $uri->withScheme('https')->withPath('/b')->withQuery('x=1');

        self::assertSame('http://example.com/a', (string)$uri);
        self::assertSame('https://example.com/b?x=1', (string)$changed);
    }

    public function testDefaultPortsAreOmittedFromString(): void
    {
        self::assertSame('http://example.com/', (string)new Uri('http://example.com:80/'));
        self::assertSame('https://example.com/', (string)new Uri('https://example.com:443/'));
    }
}
