<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

use function is_int;
use function ltrim;
use function parse_url;
use function strtolower;

final class Uri implements UriInterface
{
    /**
     * @var string
     */
    private string $scheme = '';

    /**
     * @var string
     */
    private string $userInfo = '';

    /**
     * @var string
     */
    private string $host = '';

    /**
     * @var int|mixed|null
     */
    private ?int $port = null;

    /**
     * @var string
     */
    private string $path = '';

    /**
     * @var string
     */
    private string $query = '';

    /**
     * @var string
     */
    private string $fragment = '';

    /**
     * @param string $uri
     */
    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException('Invalid URI.');
        }

        $this->scheme = strtolower((string)($parts['scheme'] ?? ''));
        $this->host = strtolower((string)($parts['host'] ?? ''));
        $this->path = (string)($parts['path'] ?? '');
        $this->query = (string)($parts['query'] ?? '');
        $this->fragment = (string)($parts['fragment'] ?? '');

        if (isset($parts['user'])) {
            $this->userInfo = (string)$parts['user'];
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }

        if (isset($parts['port'])) {
            $this->assertValidPort($parts['port']);
            $this->port = $parts['port'];
        }
    }

    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return string
     */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null && !$this->isDefaultPort()) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    /**
     * @return string
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int|null
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return string
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * @param string $scheme
     * @return UriInterface
     */
    public function withScheme(string $scheme): UriInterface
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        return $clone;
    }

    /**
     * @param string $user
     * @param string|null $password
     * @return UriInterface
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->userInfo = $password === null ? $user : $user . ':' . $password;
        return $clone;
    }

    /**
     * @param string $host
     * @return UriInterface
     */
    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = strtolower($host);
        return $clone;
    }

    /**
     * @param int|null $port
     * @return UriInterface
     */
    public function withPort(?int $port): UriInterface
    {
        $this->assertValidPort($port);
        $clone = clone $this;
        $clone->port = $port;
        return $clone;
    }

    /**
     * @param string $path
     * @return UriInterface
     */
    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    /**
     * @param string $query
     * @return UriInterface
     */
    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');
        return $clone;
    }

    /**
     * @param string $fragment
     * @return UriInterface
     */
    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');
        return $clone;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;
        if ($authority !== '' && $path === '') {
            $path = '/';
        } elseif ($authority !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * @return bool
     */
    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);
    }

    /**
     * @param mixed $port
     * @return void
     */
    private function assertValidPort(mixed $port): void
    {
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid URI port.');
        }
    }
}
