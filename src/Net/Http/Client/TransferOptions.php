<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

final class TransferOptions
{
    public function __construct(
        private readonly bool $streamResponse,
        private readonly mixed $sink,
        private readonly mixed $progress,
        private readonly int $uploadTotal
    ) {
    }

    public function streamResponse(): bool
    {
        return $this->streamResponse;
    }

    public function sink(): mixed
    {
        return $this->sink;
    }

    public function hasSink(): bool
    {
        return $this->sink !== null;
    }

    public function progress(): mixed
    {
        return $this->progress;
    }

    public function uploadTotal(): int
    {
        return $this->uploadTotal;
    }
}
