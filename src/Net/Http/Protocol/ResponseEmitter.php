<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\Response;
use Ripple\Stream;

use function sprintf;
use function strtolower;

final class ResponseEmitter
{
    /**
     * @param Response $response
     * @param Stream $stream
     * @return void
     * @throws Stream\Exception\ConnectionException
     */
    public function emit(ResponseInterface $response, Stream $stream): void
    {
        $bytes = sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        $body = $response->getBody();
        $headers = $response->getHeaders();
        if (!$response->hasHeader('Content-Length') && $body->getSize() !== null) {
            $headers['Content-Length'] = [(string)$body->getSize()];
        }

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        $stream->writeAll($bytes . "\r\n");

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $stream->writeAll($chunk);
        }

        $connection = strtolower($response->getHeaderLine('Connection'));
        $shouldClose = $connection === 'close' || $response->shouldCloseAfterBody();

        if ($shouldClose) {
            $stream->close();
        }
    }
}
