<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client\Body;

use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Exception\RequestException;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use Throwable;
use RuntimeException;

use function array_key_exists;
use function basename;
use function bin2hex;
use function is_string;
use function random_bytes;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcasecmp;

final class MultipartStream extends AppendStream
{
    /**
     * @var string
     */
    private string $boundary;

    /**
     * @param array $parts
     * @param string|null $boundary
     */
    public function __construct(array $parts, ?string $boundary = null)
    {
        try {
            $this->boundary = $boundary ?? bin2hex(random_bytes(20));
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);

        }
        parent::__construct($this->buildStreams($parts));
    }

    public function boundary(): string
    {
        return $this->boundary;
    }

    /**
     * @return StreamInterface[]
     */
    private function buildStreams(array $parts): array
    {
        $streams = [];
        $request = new Request(method: 'POST', uri: new Uri('http://localhost/'));

        foreach ($parts as $part) {
            if (!isset($part['name']) || !array_key_exists('contents', $part)) {
                throw new RequestException('Multipart parts require name and contents.', $request);
            }

            $body = StreamFactory::forRequestBody($part['contents'], $request);
            $headers = $part['headers'] ?? [];
            $filename = $part['filename'] ?? $this->filenameFromStream($body);

            if (!$this->hasHeader($headers, 'Content-Disposition')) {
                $disposition = 'form-data; name="' . $this->escape((string)$part['name']) . '"';
                if ($filename !== null && $filename !== '') {
                    $disposition .= '; filename="' . $this->escape(basename($filename)) . '"';
                }
                $headers['Content-Disposition'] = $disposition;
            }
            if ($filename !== null && !$this->hasHeader($headers, 'Content-Type')) {
                $headers['Content-Type'] = $this->contentTypeFor($filename);
            }

            $streams[] = BodyStream::fromString("--{$this->boundary}\r\n" . $this->headersToString($headers) . "\r\n");
            $streams[] = $body;
            $streams[] = BodyStream::fromString("\r\n");
        }

        $streams[] = BodyStream::fromString("--{$this->boundary}--\r\n");
        return $streams;
    }

    private function headersToString(array $headers): string
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }
        return $lines;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header => $_) {
            if (strcasecmp((string)$header, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function filenameFromStream(StreamInterface $stream): ?string
    {
        $uri = $stream->getMetadata('uri');
        return is_string($uri) && $uri !== '' && !str_starts_with($uri, 'php://') ? $uri : null;
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    }

    private function contentTypeFor(string $filename): string
    {
        return str_ends_with($filename, '.txt') ? 'text/plain' : 'application/octet-stream';
    }
}
