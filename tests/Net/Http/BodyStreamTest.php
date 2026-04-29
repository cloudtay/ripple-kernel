<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;

use function fopen;
use function fwrite;
use function rewind;

final class BodyStreamTest extends TestCase
{
    public function testCreatesReadableSeekableStreamFromString(): void
    {
        $stream = BodyStream::fromString('hello');

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertSame(5, $stream->getSize());
        self::assertSame('hello', (string) $stream);
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isSeekable());
        $stream->rewind();
        self::assertSame('he', $stream->read(2));
        $stream->rewind();
        self::assertSame('hello', $stream->getContents());
    }

    public function testWrapsWritableResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);

        fwrite($resource, 'abc');
        rewind($resource);

        $stream = new BodyStream($resource);
        self::assertTrue($stream->isWritable());
        self::assertSame('abc', $stream->getContents());
        self::assertSame(3, $stream->write('def'));
        $stream->rewind();
        self::assertSame('abcdef', $stream->getContents());
    }

    public function testDetachMakesStreamUnusable(): void
    {
        $stream = BodyStream::fromString('body');
        $detached = $stream->detach();

        self::assertIsResource($detached);
        self::assertFalse($stream->isReadable());
        self::assertSame(null, $stream->getSize());
    }
}
