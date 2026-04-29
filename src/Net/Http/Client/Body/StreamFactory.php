<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client\Body;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Exception\RequestException;

use function is_resource;
use function is_scalar;

final class StreamFactory
{
    public static function forRequestBody(mixed $body, RequestInterface $request): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            if ($body->getSize() === null) {
                throw new RequestException('Request body size is unknown.', $request);
            }
            return $body;
        }

        if (is_resource($body)) {
            $stream = new BodyStream($body);
            if ($stream->getSize() === null) {
                throw new RequestException('Request body resource size is unknown.', $request);
            }
            return $stream;
        }

        if ($body === null || $body === '') {
            return BodyStream::fromString('');
        }

        if (is_scalar($body)) {
            return BodyStream::fromString((string)$body);
        }

        throw new RequestException('Unsupported request body type.', $request);
    }
}
