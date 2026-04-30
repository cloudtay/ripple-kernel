<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client\Body;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function count;
use function strlen;

use const SEEK_SET;

class AppendStream implements StreamInterface
{
    private int $current = 0;
    private int $position = 0;
    private bool $seekable = true;

    /**
     * @param StreamInterface[] $streams
     */
    public function __construct(private array $streams)
    {
        foreach ($streams as $stream) {
            if (!$stream->isReadable()) {
                throw new RuntimeException('AppendStream expects readable streams.');
            }
            $this->seekable = $this->seekable && $stream->isSeekable();
        }
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }
        $this->streams = [];
        $this->current = 0;
        $this->position = 0;
    }

    public function detach(): mixed
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        $size = 0;
        foreach ($this->streams as $stream) {
            $partSize = $stream->getSize();
            if ($partSize === null) {
                return null;
            }
            $size += $partSize;
        }
        return $size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->streams === [] || (
            $this->current >= count($this->streams) - 1
            && $this->streams[$this->current]->eof()
        );
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->seekable || $whence !== SEEK_SET || $offset !== 0) {
            throw new RuntimeException('AppendStream only supports rewind.');
        }
        $this->rewind();
    }

    public function rewind(): void
    {
        if (!$this->seekable) {
            throw new RuntimeException('AppendStream is not seekable.');
        }
        foreach ($this->streams as $stream) {
            $stream->rewind();
        }
        $this->current = 0;
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('AppendStream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $buffer = '';
        while ($length > 0 && !$this->eof()) {
            $chunk = $this->streams[$this->current]->read($length);
            if ($chunk === '' && $this->streams[$this->current]->eof()) {
                $this->current++;
                continue;
            }
            $buffer .= $chunk;
            $length -= strlen($chunk);
        }
        $this->position += strlen($buffer);
        return $buffer;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }
        return $contents;
    }

    /**
     * @param string|null $key
     * @return array|null
     */
    public function getMetadata(?string $key = null): ?array
    {
        return $key === null ? [] : null;
    }
}
