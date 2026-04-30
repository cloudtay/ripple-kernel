<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

final class TransferOptions
{
    /**
     * @param bool $streamResponse
     * @param mixed $sink
     * @param mixed $progress
     * @param int $uploadTotal
     */
    public function __construct(
        private readonly bool $streamResponse,
        private readonly mixed $sink,
        private readonly mixed $progress,
        private readonly int $uploadTotal
    ) {
    }

    /**
     * @return bool
     */
    public function streamResponse(): bool
    {
        return $this->streamResponse;
    }

    /**
     * @return mixed
     */
    public function sink(): mixed
    {
        return $this->sink;
    }

    /**
     * @return bool
     */
    public function hasSink(): bool
    {
        return $this->sink !== null;
    }

    /**
     * @return mixed
     */
    public function progress(): mixed
    {
        return $this->progress;
    }

    /**
     * @return int
     */
    public function uploadTotal(): int
    {
        return $this->uploadTotal;
    }
}
