<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Generator;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\Enum\Status;

use function gmdate;
use function implode;
use function is_numeric;
use function is_resource;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function rawurlencode;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;
use function ucfirst;

final class Response implements ResponseInterface
{
    /**
     * @var int
     */
    private int $statusCode = 200;

    /**
     * @var string
     */
    private string $reasonPhrase = 'OK';

    /**
     * @var array
     */
    private array $cookieLines = [];

    /**
     * @var bool
     */
    private bool $closeAfterBody = false;

    /**
     * @var string
     */
    private string $protocolVersion = '1.1';

    /**
     * @var HeaderMap
     */
    private HeaderMap $headers;

    /**
     * @var StreamInterface
     */
    private StreamInterface $body;

    /**
     * @param int $statusCode
     * @param array $headers
     * @param StreamInterface|string|null $body
     * @param string $reasonPhrase
     */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $reasonPhrase = ''
    ) {
        $this->headers = new HeaderMap($headers);
        $this->body = $body instanceof StreamInterface ? $body : BodyStream::fromString((string)($body ?? ''));
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (Status::messageForCode($statusCode) ?? '');
    }

    /**
     * @return string
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @param string $version
     * @return MessageInterface
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    /**
     * 获取全部消息头
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /**
     * 判断消息头是否存在
     * @param string $name 消息头名称
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * 获取消息头值列表
     * @param string $name 消息头名称
     * @return string[]
     */
    public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    /**
     * 获取消息头行
     * @param string $name 消息头名称
     * @return string
     */
    public function getHeaderLine(string $name): string
    {
        return $this->headers->line($name);
    }

    /**
     * 返回替换消息头后的消息实例
     * @param string $name 消息头名称
     * @param string|string[] $value 消息头值
     * @return MessageInterface
     */
    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers->set($name, $value);
        return $clone;
    }

    /**
     * 返回追加消息头后的消息实例
     * @param string $name 消息头名称
     * @param string|string[] $value 消息头值
     * @return MessageInterface
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers->add($name, $value);
        return $clone;
    }

    /**
     * @param string $name
     * @return MessageInterface
     */
    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        $clone->headers->remove($name);
        return $clone;
    }

    /**
     * @return StreamInterface
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * @param string $content
     * @param int $status
     * @param array $headers
     * @return self
     */
    public static function text(string $content, int $status = 200, array $headers = []): self
    {
        $response = new self($status, ['Content-Type' => 'text/plain'] + $headers, $content);
        return self::withContentLength($response, $content);
    }

    /**
     * @param string $content
     * @param int $status
     * @param array $headers
     * @return self
     */
    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        $response = new self($status, ['Content-Type' => 'text/html'] + $headers, $content);
        return self::withContentLength($response, $content);
    }

    /**
     * @param mixed $content
     * @param int $status
     * @param array $headers
     * @return self
     */
    public static function json(mixed $content, int $status = 200, array $headers = []): self
    {
        $encoded = is_string($content) ? $content : json_encode($content);
        $encoded = (string)$encoded;
        $response = new self($status, ['Content-Type' => 'application/json'] + $headers, $encoded);
        return self::withContentLength($response, $encoded);
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @param int $code
     * @param string $reasonPhrase
     * @return ResponseInterface
     */
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException('Invalid HTTP status code.');
        }

        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (Status::messageForCode($code) ?? '');
        return $clone;
    }

    /**
     * @return string
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return int
     */
    public function statusCode(): int
    {
        return $this->getStatusCode();
    }

    /**
     * @return string
     */
    public function statusText(): string
    {
        return $this->getReasonPhrase();
    }

    /**
     * @param int $statusCode
     * @return $this
     */
    public function setStatusCode(int $statusCode): Response
    {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = Status::messageForCode($statusCode) ?? $this->reasonPhrase;
        return $this;
    }

    /**
     * @param string $statusText
     * @return $this
     */
    public function setStatusText(string $statusText): Response
    {
        $this->reasonPhrase = $statusText;
        return $this;
    }

    /**
     * @param string|null $name
     * @return string|array|null
     */
    public function header(null|string $name = null): string|array|null
    {
        if ($name === null) {
            return $this->getHeaders();
        }

        $values = $this->getHeader($name);
        return $values[1] ?? $values[0] ?? null;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function cookie(string $name): mixed
    {
        return $this->cookieLines[$name] ?? null;
    }

    /**
     * @param string $name
     * @param string $content
     * @param array|null $options
     * @return $this
     */
    public function withCookie(string $name, string $content, ?array $options = []): Response
    {
        $options = $options ?? [];
        $name = trim($name);
        if ($name === '') {
            return $this;
        }

        $value = str_replace([';', "\r", "\n", "\0"], '', rawurlencode($content));
        $parts = ["{$name}={$value}"];

        if (isset($options['expires']) && is_numeric($options['expires'])) {
            $expires = (int)$options['expires'];
            if ($expires > 0) {
                $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires);
            }
        }

        if (isset($options['maxAge']) && is_numeric($options['maxAge'])) {
            $maxAge = (int)$options['maxAge'];
            $parts[] = "Max-Age={$maxAge}";
            if ($maxAge <= 0) {
                $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            }
        }

        $path = !empty($options['path']) ? trim((string)$options['path']) : '/';
        $parts[] = 'Path=' . str_replace([';', "\r", "\n"], '', $path);

        if (!empty($options['domain'])) {
            $domain = trim((string)$options['domain'], '. ');
            if ($domain !== '') {
                $parts[] = 'Domain=' . str_replace([';', "\r", "\n"], '', $domain);
            }
        }

        if (!empty($options['secure'])) {
            $parts[] = 'Secure';
        }

        if (!empty($options['httponly']) || !empty($options['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }

        if (!empty($options['samesite'])) {
            $sameSite = strtolower(trim((string)$options['samesite']));
            if ($sameSite === 'strict' || $sameSite === 'lax' || $sameSite === 'none') {
                $parts[] = 'SameSite=' . ucfirst($sameSite);
            }
        }

        $this->cookieLines[$name] = implode('; ', $parts);
        return $this;
    }

    /**
     * @param string $cookieLine
     * @return $this
     */
    public function withCookieLine(string $cookieLine): Response
    {
        $this->cookieLines[] = $cookieLine;
        return $this;
    }

    /**
     * @param string $name
     * @return Response
     */
    public function removeHeader(string $name): MessageInterface
    {
        return $this->withoutHeader($name);
    }

    /**
     * @return $this
     */
    public function closeAfter(): Response
    {
        $this->closeAfterBody = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function shouldCloseAfterBody(): bool
    {
        return $this->closeAfterBody;
    }

    /**
     * @return string
     */
    public function body(): string
    {
        return (string)$this->body;
    }

    /**
     * @param mixed $body
     * @return Response|$this
     */
    public function withBody(mixed $body): Response
    {
        if ($body instanceof StreamInterface) {
            return $this->withPsrBody($body);
        }

        if (is_resource($body)) {
            return $this->withPsrBody(new BodyStream($body));
        }

        if ($body instanceof Generator) {
            $body = implode('', iterator_to_array($body));
        }

        $content = (string)$body;
        return self::withContentLength($this->withPsrBody(BodyStream::fromString($content)), $content);
    }

    /**
     * @param StreamInterface $body
     * @return $this
     */
    private function withPsrBody(StreamInterface $body): Response
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    /**
     * @param Response $response
     * @param string $content
     * @return Response
     */
    private static function withContentLength(self $response, string $content): MessageInterface
    {
        return $response->withHeader('Content-Length', (string)strlen($content));
    }

    /**
     * @return void
     */
    public function __clone()
    {
        $this->headers = new HeaderMap($this->headers->all());
    }
}
