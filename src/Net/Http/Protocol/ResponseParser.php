<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Ripple\Net\Http\Response;
use RuntimeException;

use function ctype_xdigit;
use function explode;
use function hexdec;
use function preg_match;
use function str_contains;
use function stripos;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function count;
use function implode;

final class ResponseParser
{
    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @param string $chunk
     * @return array
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $responses = [];

        while (($response = $this->tryParseOne()) instanceof Response) {
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * @return Response|null
     */
    private function tryParseOne(): ?Response
    {
        $headerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $head = substr($this->buffer, 0, $headerEnd);
        $rest = substr($this->buffer, $headerEnd + 4);
        $lines = explode("\r\n", $head);
        $statusLine = $lines[0] ?? '';

        if (!preg_match('#^HTTP/(\d+(?:\.\d+)?)\s+(\d{3})\s*(.*)$#', $statusLine, $matches)) {
            throw new RuntimeException('Invalid HTTP status line.');
        }

        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            if (!str_contains($lines[$i], ':')) {
                continue;
            }
            [$name, $value] = explode(':', $lines[$i], 2);
            $headers[trim($name)][] = trim($value);
        }

        $body = null;
        $consumed = 0;
        $isChunked = false;
        $contentLength = null;

        foreach ($headers as $name => $values) {
            if (stripos($name, 'Transfer-Encoding') === 0 && stripos(implode(', ', $values), 'chunked') !== false) {
                $isChunked = true;
            }
            if (stripos($name, 'Content-Length') === 0) {
                $contentLength = (int)$values[0];
            }
        }

        if ($isChunked) {
            [$body, $consumed] = $this->parseChunked($rest);
            if ($body === null) {
                return null;
            }
            foreach ($headers as $name => $_) {
                if (stripos($name, 'Transfer-Encoding') === 0) {
                    unset($headers[$name]);
                }
            }
        } elseif ($contentLength !== null) {
            if (strlen($rest) < $contentLength) {
                return null;
            }
            $body = substr($rest, 0, $contentLength);
            $consumed = $contentLength;
        } else {
            $body = '';
            $consumed = 0;
        }

        $this->buffer = substr($rest, $consumed);

        return (new Response((int)$matches[2], $headers, $body, trim($matches[3])))
            ->withProtocolVersion($matches[1]);
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

            $hex = trim(substr($buffer, $offset, $lineEnd - $offset));
            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new RuntimeException('Invalid HTTP chunk size.');
            }

            $length = (int)hexdec($hex);
            $offset = $lineEnd + 2;

            if (strlen($buffer) < $offset + $length + 2) {
                return [null, 0];
            }

            if ($length === 0) {
                return [$body, $offset + 2];
            }

            $body .= substr($buffer, $offset, $length);
            $offset += $length + 2;
        }
    }
}
