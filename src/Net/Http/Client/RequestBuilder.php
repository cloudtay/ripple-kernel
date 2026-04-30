<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Client\Body\MultipartStream;
use Ripple\Net\Http\Client\Body\StreamFactory;
use Ripple\Net\Http\Exception\RequestException;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function count;
use function http_build_query;
use function json_encode;
use function strtoupper;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class RequestBuilder
{
    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return array{0:Request,1:TransferOptions}
     */
    public function build(string $method, string $uri, array $options): array
    {
        $headers = $options['headers'] ?? [];
        $bodyKeys = array_intersect(['body', 'json', 'form_params', 'multipart'], array_keys($options));
        $request = new Request(method: strtoupper($method), uri: new Uri($uri), headers: $headers);

        if (count($bodyKeys) > 1) {
            throw new RequestException('Only one request body option may be used.', $request);
        }

        $body = BodyStream::fromString('');
        if (isset($options['json'])) {
            $body = BodyStream::fromString((string)json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $headers['Content-Type'] ??= 'application/json';
        } elseif (isset($options['form_params'])) {
            $body = BodyStream::fromString(http_build_query($options['form_params']));
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        } elseif (isset($options['multipart'])) {
            $body = new MultipartStream($options['multipart']);
            $headers['Content-Type'] ??= 'multipart/form-data; boundary=' . $body->boundary();
        } elseif (array_key_exists('body', $options)) {
            $body = StreamFactory::forRequestBody($options['body'], $request);
        }

        $size = $body->getSize();
        if ($size === null) {
            throw new RequestException('Request body size is unknown.', $request);
        }
        if ($size > 0) {
            $headers['Content-Length'] ??= (string)$size;
        }

        $request = new Request(method: strtoupper($method), uri: new Uri($uri), headers: $headers, body: $body);

        return [
            $request,
            new TransferOptions(
                (bool)($options['stream'] ?? false),
                $options['sink'] ?? null,
                $options['progress'] ?? null,
                $size
            ),
        ];
    }
}
