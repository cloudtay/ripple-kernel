<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\MessageInterface;
use Ripple\Net\Http\Exception\ProtocolException;
use Ripple\Net\Http\Response;

use function array_slice;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function preg_match;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;
use function str_contains;

final class ResponseParser
{
    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @var array{0:int,1:array,2:string,3:string}|null
     */
    private ?array $closeDelimited = null;

    public function __construct(
        private readonly string $method = 'GET',
        private readonly int $maxHeaderBytes = 65536,
        private readonly int $maxBodyBytes = 0
    ) {
    }

    /**
     * @return Response[]
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $this->assertHeaderLimit();

        $responses = [];
        while (($response = $this->tryParseOne()) instanceof Response) {
            if ($response->getStatusCode() >= 100 && $response->getStatusCode() < 200) {
                continue;
            }
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * @return Response[]
     */
    public function finish(): array
    {
        if ($this->closeDelimited === null) {
            return [];
        }

        $body = $this->buffer;
        $this->assertBodyLimit(strlen($body));
        $this->buffer = '';

        [$statusCode, $headers, $reasonPhrase, $protocolVersion] = $this->closeDelimited;
        $this->closeDelimited = null;

        return [
            (new Response($statusCode, $headers, $body, $reasonPhrase))->withProtocolVersion($protocolVersion),
        ];
    }

    /**
     * @return Response|null
     */
    private function tryParseOne(): MessageInterface|null
    {
        if ($this->closeDelimited !== null) {
            $this->assertBodyLimit(strlen($this->buffer));
            return null;
        }

        $headerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            $this->assertHeaderLimit();
            return null;
        }
        if ($this->maxHeaderBytes > 0 && $headerEnd + 4 > $this->maxHeaderBytes) {
            throw new ProtocolException('HTTP response headers exceed configured limit.');
        }

        $head = substr($this->buffer, 0, $headerEnd);
        $rest = substr($this->buffer, $headerEnd + 4);
        $lines = explode("\r\n", $head);
        $statusLine = $lines[0] ?? '';

        if (!preg_match('#^HTTP/(\d+(?:\.\d+)?)\s+(\d{3})\s*(.*)$#', $statusLine, $matches)) {
            throw new ProtocolException('Invalid HTTP status line.');
        }

        $headers = $this->parseHeaders(array_slice($lines, 1));
        $statusCode = (int)$matches[2];
        $protocolVersion = $matches[1];
        $reasonPhrase = trim($matches[3]);

        if ($statusCode >= 100 && $statusCode < 200) {
            $this->buffer = $rest;
            return (new Response($statusCode, $headers, '', $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        if ($this->isNoBodyResponse($statusCode)) {
            $this->buffer = $rest;
            return (new Response($statusCode, $headers, '', $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        $isChunked = $this->isChunked($headers);
        $contentLength = $this->contentLength($headers);
        if ($isChunked && $contentLength !== null) {
            throw new ProtocolException('Response cannot contain both Content-Length and Transfer-Encoding.');
        }

        if ($isChunked) {
            [$body, $consumed] = $this->parseChunked($rest);
            if ($body === null) {
                return null;
            }
            $this->assertBodyLimit(strlen($body));
            $headers = $this->removeHeader($headers, 'Transfer-Encoding');
            $this->buffer = substr($rest, $consumed);
            return (new Response($statusCode, $headers, $body, $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        if ($contentLength !== null) {
            if (strlen($rest) < $contentLength) {
                $this->assertBodyLimit(strlen($rest));
                return null;
            }
            $this->assertBodyLimit($contentLength);
            $body = substr($rest, 0, $contentLength);
            $this->buffer = substr($rest, $contentLength);
            return (new Response($statusCode, $headers, $body, $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        $this->closeDelimited = [$statusCode, $headers, $reasonPhrase, $protocolVersion];
        $this->buffer = $rest;
        $this->assertBodyLimit(strlen($this->buffer));
        return null;
    }

    /**
     * @param array $lines
     * @return array
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, ':')) {
                throw new ProtocolException('Invalid HTTP header line.');
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array $headers
     * @return int|null
     */
    private function contentLength(array $headers): ?int
    {
        $length = null;
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Content-Length') !== 0) {
                continue;
            }
            foreach ($values as $value) {
                $current = (int)trim((string)$value);
                if ($length !== null && $length !== $current) {
                    throw new ProtocolException('Conflicting Content-Length headers.');
                }
                $length = $current;
            }
        }

        return $length;
    }

    /**
     * @param array $headers
     * @return bool
     */
    private function isChunked(array $headers): bool
    {
        return TransferEncoding::isChunked($headers);
    }

    /**
     * @param int $statusCode
     * @return bool
     */
    private function isNoBodyResponse(int $statusCode): bool
    {
        return strtoupper($this->method) === 'HEAD' || $statusCode === 204 || $statusCode === 304;
    }

    /**
     * @param string $buffer
     * @return array
     */
    private function parseChunked(string $buffer): array
    {
        $offset = 0;
        $body = '';

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $offset);
            if ($lineEnd === false) {
                return [null, 0];
            }

            $line = trim(substr($buffer, $offset, $lineEnd - $offset));
            $parts = explode(';', $line, 2);
            $hex = trim($parts[0]);
            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new ProtocolException('Invalid HTTP chunk size.');
            }

            $length = (int)hexdec($hex);
            $offset = $lineEnd + 2;

            if ($length === 0) {
                $trailerEnd = strpos($buffer, "\r\n\r\n", $offset);
                if ($trailerEnd === false) {
                    if (substr($buffer, $offset, 2) === "\r\n") {
                        return [$body, $offset + 2];
                    }
                    return [null, 0];
                }

                return [$body, $trailerEnd + 4];
            }

            if (strlen($buffer) < $offset + $length + 2) {
                return [null, 0];
            }

            if (substr($buffer, $offset + $length, 2) !== "\r\n") {
                throw new ProtocolException('Invalid HTTP chunk terminator.');
            }

            $body .= substr($buffer, $offset, $length);
            $this->assertBodyLimit(strlen($body));
            $offset += $length + 2;
        }
    }

    /**
     * @param array $headers
     * @param string $name
     * @return array
     */
    private function removeHeader(array $headers, string $name): array
    {
        foreach ($headers as $headerName => $_) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                unset($headers[$headerName]);
            }
        }

        return $headers;
    }

    /**
     * @return void
     */
    private function assertHeaderLimit(): void
    {
        if ($this->maxHeaderBytes > 0 && !str_contains($this->buffer, "\r\n\r\n") && strlen($this->buffer) > $this->maxHeaderBytes) {
            throw new ProtocolException('HTTP response headers exceed configured limit.');
        }
    }

    /**
     * @param int $bytes
     * @return void
     */
    private function assertBodyLimit(int $bytes): void
    {
        if ($this->maxBodyBytes > 0 && $bytes > $this->maxBodyBytes) {
            throw new ProtocolException('HTTP response body exceeds configured limit.');
        }
    }
}
