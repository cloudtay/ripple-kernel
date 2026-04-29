<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Exception\ProtocolException;

use function function_exists;
use function gzdecode;
use function gzinflate;
use function strlen;
use function strtolower;
use function trim;
use function brotli_uncompress;

final class ResponseDecoder
{
    /**
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function decode(ResponseInterface $response): ResponseInterface
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        if ($encoding === '') {
            return $response;
        }

        $body = (string)$response->getBody();
        $decoded = match ($encoding) {
            'gzip' => @gzdecode($body),
            'deflate' => @gzinflate($body),
            'br' => $this->decodeBrotli($body),
            default => throw new ProtocolException("Unsupported content encoding: {$encoding}"),
        };

        if ($decoded === false) {
            throw new ProtocolException("Failed to decode {$encoding} response body.");
        }

        return $response
            ->withoutHeader('Content-Encoding')
            ->withHeader('Content-Length', (string)strlen($decoded))
            ->withBody(BodyStream::fromString($decoded));
    }

    /**
     * @return bool
     */
    public static function supportsBrotli(): bool
    {
        return function_exists('brotli_uncompress');
    }

    /**
     * @param string $body
     * @return string|false
     */
    private function decodeBrotli(string $body): string|false
    {
        if (!self::supportsBrotli()) {
            throw new ProtocolException('Brotli response decoding is not supported by this runtime.');
        }

        return brotli_uncompress($body);
    }
}
