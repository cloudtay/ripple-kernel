<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\RequestInterface;
use Ripple\Net\Http\Exception\RequestException;

use function extension_loaded;
use function function_exists;
use function preg_match;
use function sprintf;
use function strcasecmp;
use function str_contains;
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
        if (!$request->hasHeader('User-Agent')) {
            $headers['User-Agent'] = ['Ripple-Kernel'];
        }
        if (!$request->hasHeader('Accept-Encoding')) {
            $headers['Accept-Encoding'] = [$this->defaultAcceptEncoding()];
        }

        if ($this->hasHeader($headers, 'Content-Length') && $this->hasHeader($headers, 'Transfer-Encoding')) {
            throw new RequestException('Request cannot contain both Content-Length and Transfer-Encoding.', $request);
        }
        if ($this->hasHeader($headers, 'Transfer-Encoding')) {
            throw new RequestException('Request Transfer-Encoding is not supported in this client pass.', $request);
        }

        $body = (string)$request->getBody();
        if ($body !== '' && !$this->hasHeader($headers, 'Content-Length')) {
            $headers['Content-Length'] = [(string)strlen($body)];
        }

        foreach ($headers as $name => $values) {
            $this->assertHeaderName($request, (string)$name);
            foreach ((array)$values as $value) {
                $value = (string)$value;
                $this->assertHeaderValue($request, $value);
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        return $bytes . "\r\n" . $body;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $headerName => $_) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function assertHeaderName(RequestInterface $request, string $name): void
    {
        if ($name === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new RequestException("Invalid request header name: {$name}", $request);
        }
    }

    private function assertHeaderValue(RequestInterface $request, string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RequestException('Invalid request header value: CRLF is not allowed.', $request);
        }
    }

    private function defaultAcceptEncoding(): string
    {
        $encodings = 'gzip, deflate';
        if (extension_loaded('brotli') || function_exists('brotli_uncompress')) {
            $encodings .= ', br';
        }

        return $encodings;
    }
}
