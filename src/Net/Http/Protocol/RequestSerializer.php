<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\RequestInterface;

use function sprintf;
use function strlen;

final class RequestSerializer
{
    /**
     * @param RequestInterface $request
     * @return string
     */
    public function serialize(RequestInterface $request): string
    {
        $target = $request->getRequestTarget();
        $bytes = sprintf(
            "%s %s HTTP/%s\r\n",
            $request->getMethod(),
            $target === '' ? '/' : $target,
            $request->getProtocolVersion()
        );

        $headers = $request->getHeaders();
        if (!$request->hasHeader('Host') && $request->getUri()->getHost() !== '') {
            $headers = ['Host' => [$request->getUri()->getAuthority()]] + $headers;
        }

        $body = (string)$request->getBody();
        if ($body !== '' && !$request->hasHeader('Content-Length')) {
            $headers['Content-Length'] = [(string)strlen($body)];
        }

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        return $bytes . "\r\n" . $body;
    }
}
