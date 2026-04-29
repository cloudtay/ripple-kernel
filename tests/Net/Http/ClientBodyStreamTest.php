<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Client\Body\AppendStream;
use Ripple\Net\Http\Client\Body\MultipartStream;
use Ripple\Net\Http\Client\Body\StreamFactory;
use Ripple\Net\Http\Exception\RequestException;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use RuntimeException;

use function fopen;
use function fwrite;
use function rewind;
use function strlen;

use const SEEK_SET;

final class ClientBodyStreamTest extends TestCase
{
    public function testAppendStreamReadsSequentiallyAndReportsSize(): void
    {
        $stream = new AppendStream([
            BodyStream::fromString('ab'),
            BodyStream::fromString('cd'),
        ]);

        self::assertSame(4, $stream->getSize());
        self::assertSame('abc', $stream->read(3));
        self::assertSame('d', $stream->read(3));
        self::assertTrue($stream->eof());
    }

    public function testStreamFactoryWrapsKnownResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, 'hello');
        rewind($resource);

        $stream = StreamFactory::forRequestBody($resource, new Request(method: 'POST', uri: new Uri('http://example.com/')));

        self::assertSame(5, $stream->getSize());
        self::assertSame('hello', $stream->read(5));
    }

    public function testStreamFactoryRejectsUnknownLengthStream(): void
    {
        $this->expectException(RequestException::class);

        $unknown = new class () implements StreamInterface {
            private StreamInterface $inner;

            public function __construct()
            {
                $this->inner = BodyStream::fromString('abc');
            }

            public function __toString(): string
            {
                return (string)$this->inner;
            }

            public function close(): void
            {
                $this->inner->close();
            }

            public function detach(): mixed
            {
                return $this->inner->detach();
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return $this->inner->tell();
            }

            public function eof(): bool
            {
                return $this->inner->eof();
            }

            public function isSeekable(): bool
            {
                return $this->inner->isSeekable();
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                $this->inner->seek($offset, $whence);
            }

            public function rewind(): void
            {
                $this->inner->rewind();
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new RuntimeException('not writable');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                return $this->inner->read($length);
            }

            public function getContents(): string
            {
                return $this->inner->getContents();
            }

            public function getMetadata(?string $key = null): mixed
            {
                return $this->inner->getMetadata($key);
            }
        };

        StreamFactory::forRequestBody($unknown, new Request(method: 'POST', uri: new Uri('http://example.com/')));
    }

    public function testMultipartStreamBuildsKnownSizeBody(): void
    {
        $stream = new MultipartStream([
            ['name' => 'title', 'contents' => 'report'],
            ['name' => 'file', 'contents' => BodyStream::fromString('abc'), 'filename' => 'a.txt'],
        ], 'boundary123');

        $body = (string)$stream;

        self::assertSame(strlen($body), $stream->getSize());
        self::assertStringContainsString("--boundary123\r\n", $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="title"', $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="a.txt"', $body);
        self::assertStringContainsString("Content-Type: text/plain\r\n", $body);
        self::assertStringEndsWith("--boundary123--\r\n", $body);
    }

    public function testMultipartRejectsMissingNameOrContents(): void
    {
        $this->expectException(RequestException::class);

        new MultipartStream([
            ['name' => 'file'],
        ], 'boundary123');
    }
}
