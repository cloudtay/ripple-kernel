<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function file_put_contents;

use const UPLOAD_ERR_OK;

final class UploadedFile implements UploadedFileInterface
{
    /**
     * @var bool
     */
    private bool $moved = false;

    /**
     * @param StreamInterface $stream
     * @param int|null $size
     * @param int $error
     * @param string|null $clientFilename
     * @param string|null $clientMediaType
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int            $size,
        private readonly int             $error = UPLOAD_ERR_OK,
        private readonly ?string         $clientFilename = null,
        private readonly ?string         $clientMediaType = null
    ) {
    }

    /**
     * @return StreamInterface
     */
    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        return $this->stream;
    }

    /**
     * @param string $targetPath
     * @return void
     */
    public function moveTo(string $targetPath): void
    {
        if ($targetPath === '') {
            throw new InvalidArgumentException('Upload target path cannot be empty.');
        }

        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        $this->stream->rewind();
        if (file_put_contents($targetPath, $this->stream->getContents()) === false) {
            throw new RuntimeException('Unable to move uploaded file.');
        }

        $this->moved = true;
    }

    /**
     * @return int|null
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * @return int
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * @return string|null
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * @return string|null
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
