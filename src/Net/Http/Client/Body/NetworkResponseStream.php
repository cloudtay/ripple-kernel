<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client\Body;

use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\Exception\ProtocolException;
use RuntimeException;
use Throwable;

use function ctype_xdigit;
use function explode;
use function hexdec;
use function min;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const SEEK_SET;

final class NetworkResponseStream implements StreamInterface
{
    private bool $closed = false;

    private bool $eof = false;

    private int $position = 0;

    private int $chunkRemaining = 0;

    private int $downloaded = 0;

    /**
     * @param callable():string $readRaw
     * @param callable():void $closeRaw
     * @param callable(int):void|null $progress
     */
    public function __construct(
        private string $buffer,
        private readonly ?int $contentLength,
        private readonly bool $chunked,
        private readonly int $maxBodyBytes,
        private readonly mixed $readRaw,
        private readonly mixed $closeRaw,
        private readonly mixed $progress = null
    ) {
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        ($this->closeRaw)();
        $this->closed = true;
        $this->buffer = '';
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return $this->contentLength;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Network response stream is not seekable.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Network response stream is not writable.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        if ($length <= 0 || $this->closed || $this->eof) {
            return '';
        }

        $data = $this->chunked ? $this->readChunked($length) : $this->readFixed($length);
        $this->position += strlen($data);
        return $data;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(8192);
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = ['eof' => $this->eof, 'seekable' => false];
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function readFixed(int $length): string
    {
        $remaining = ($this->contentLength ?? 0) - $this->downloaded;
        if ($remaining <= 0) {
            $this->eof = true;
            return '';
        }

        $wanted = min($length, $remaining);
        while (strlen($this->buffer) < $wanted) {
            $this->appendRaw();
        }

        $data = substr($this->buffer, 0, $wanted);
        $this->buffer = substr($this->buffer, $wanted);
        $this->markDownloaded(strlen($data));

        if ($this->downloaded >= ($this->contentLength ?? 0)) {
            $this->eof = true;
        }

        return $data;
    }

    private function readChunked(int $length): string
    {
        $data = '';

        while (strlen($data) < $length && !$this->eof) {
            if ($this->chunkRemaining === 0) {
                $this->readNextChunkHeader();
                if ($this->eof) {
                    break;
                }
            }

            $wanted = min($length - strlen($data), $this->chunkRemaining);
            while (strlen($this->buffer) < $wanted) {
                $this->appendRaw();
            }

            $part = substr($this->buffer, 0, $wanted);
            $this->buffer = substr($this->buffer, $wanted);
            $data .= $part;
            $this->chunkRemaining -= strlen($part);
            $this->markDownloaded(strlen($part));

            if ($this->chunkRemaining === 0) {
                $this->consumeChunkTerminator();
            }
        }

        return $data;
    }

    private function readNextChunkHeader(): void
    {
        while (($lineEnd = strpos($this->buffer, "\r\n")) === false) {
            $this->appendRaw();
        }

        $line = trim(substr($this->buffer, 0, $lineEnd));
        $this->buffer = substr($this->buffer, $lineEnd + 2);
        $parts = explode(';', $line, 2);
        $hex = trim($parts[0]);
        if ($hex === '' || !ctype_xdigit($hex)) {
            throw new ProtocolException('Invalid HTTP chunk size.');
        }

        $this->chunkRemaining = (int)hexdec($hex);
        if ($this->chunkRemaining === 0) {
            $this->consumeTrailers();
            $this->eof = true;
        }
    }

    private function consumeChunkTerminator(): void
    {
        while (strlen($this->buffer) < 2) {
            $this->appendRaw();
        }
        if (substr($this->buffer, 0, 2) !== "\r\n") {
            throw new ProtocolException('Invalid HTTP chunk terminator.');
        }

        $this->buffer = substr($this->buffer, 2);
    }

    private function consumeTrailers(): void
    {
        while (strpos($this->buffer, "\r\n\r\n") === false && substr($this->buffer, 0, 2) !== "\r\n") {
            $this->appendRaw();
        }

        if (substr($this->buffer, 0, 2) === "\r\n") {
            $this->buffer = substr($this->buffer, 2);
            return;
        }

        $trailerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($trailerEnd !== false) {
            $this->buffer = substr($this->buffer, $trailerEnd + 4);
        }
    }

    private function markDownloaded(int $bytes): void
    {
        $this->downloaded += $bytes;
        if ($this->maxBodyBytes > 0 && $this->downloaded > $this->maxBodyBytes) {
            throw new ProtocolException('HTTP response body exceeds configured limit.');
        }

        if ($this->progress !== null) {
            ($this->progress)($this->downloaded);
        }
    }

    private function appendRaw(): void
    {
        $chunk = ($this->readRaw)();
        if ($chunk === '') {
            throw new ProtocolException('HTTP response body ended unexpectedly.');
        }

        $this->buffer .= $chunk;
    }
}
