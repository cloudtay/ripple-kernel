<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Ripple\Stream;
use RuntimeException;

use function array_merge;
use function is_array;
use function is_object;
use function is_string;
use function json_encode;
use function preg_match;
use function strtoupper;

final class Request implements ServerRequestInterface
{
    /**
     * @var array
     */
    public array $GET;

    /**
     * @var array
     */
    public array $POST;

    /**
     * @var array
     */
    public array $COOKIE;

    /**
     * @var array
     */
    public array $FILES;

    /**
     * @var array
     */
    public array $SERVER;

    /**
     * @var mixed
     */
    public mixed $CONTENT;

    /**
     * @var array
     */
    public array $REQUEST;

    /**
     * @var string
     */
    private string $method;

    /**
     * @var Uri|UriInterface
     */
    private UriInterface|Uri $uri;

    /**
     * @var string|null
     */
    private ?string $requestTarget;

    /**
     * @var array
     */
    private array $serverParams;

    /**
     * @var array
     */
    private array $cookieParams;

    /**
     * @var array
     */
    private array $queryParams;

    /**
     * @var mixed|array
     */
    private mixed $parsedBody;

    /**
     * @var array
     */
    private array $uploadedFiles;

    /**
     * @var array
     */
    private array $attributes;

    /**
     * @var Stream|null
     */
    private ?Stream $stream;

    /**
     * @var ServerResponder|null
     */
    private ?ServerResponder $responder = null;

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
     * @param string $method
     * @param UriInterface|string|null $uri
     * @param array $headers
     * @param StreamInterface|string|null $body
     * @param array $serverParams
     * @param array $queryParams
     * @param mixed $parsedBody
     * @param array $cookieParams
     * @param array $uploadedFiles
     * @param array $attributes
     * @param string|null $requestTarget
     * @param array $GET
     * @param array $POST
     * @param array $COOKIE
     * @param array $FILES
     * @param array $SERVER
     * @param mixed|null $CONTENT
     * @param Stream|null $stream
     */
    public function __construct(
        string                      $method = 'GET',
        UriInterface|string|null    $uri = null,
        array                       $headers = [],
        StreamInterface|string|null $body = null,
        array                       $serverParams = [],
        array                       $queryParams = [],
        mixed                       $parsedBody = [],
        array                       $cookieParams = [],
        array                       $uploadedFiles = [],
        array                       $attributes = [],
        ?string                     $requestTarget = null,
        array                       $GET = [],
        array                       $POST = [],
        array                       $COOKIE = [],
        array                       $FILES = [],
        array                       $SERVER = [],
        mixed                       $CONTENT = null,
        ?Stream                     $stream = null,
    ) {
        if ($SERVER !== []) {
            $serverParams = $SERVER;
            $method = (string)($SERVER['REQUEST_METHOD'] ?? $method);
        }
        if ($GET !== []) {
            $queryParams = $GET;
        }
        if ($POST !== []) {
            $parsedBody = $POST;
        }
        if ($COOKIE !== []) {
            $cookieParams = $COOKIE;
        }
        if ($FILES !== []) {
            $uploadedFiles = $FILES;
        }
        if ($CONTENT !== null) {
            $body = (string)$CONTENT;
        }

        $this->headers = new HeaderMap($headers);
        $this->body = $body instanceof StreamInterface ? $body : BodyStream::fromString((string)($body ?? ''));
        $this->method = $method;
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri((string)($uri ?? ''));
        $this->requestTarget = $requestTarget;
        $this->serverParams = $serverParams;
        $this->queryParams = $queryParams;
        $this->parsedBody = $parsedBody;
        $this->cookieParams = $cookieParams;
        $this->uploadedFiles = $uploadedFiles;
        $this->attributes = $attributes;
        $this->stream = $stream;
        $this->syncLegacyFields();

        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $this->headers->set('Host', $this->uri->getAuthority());
        }
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
     * @param StreamInterface $body
     * @return MessageInterface
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return string
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    /**
     * @param string $requestTarget
     * @return ServerRequestInterface
     */
    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @param string $method
     * @return ServerRequestInterface
     */
    public function withMethod(string $method): ServerRequestInterface
    {
        if ($method === '' || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $method)) {
            throw new InvalidArgumentException('Invalid HTTP method.');
        }

        $clone = clone $this;
        $clone->method = strtoupper($method);
        $clone->serverParams['REQUEST_METHOD'] = $clone->method;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return UriInterface
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * @param UriInterface $uri
     * @param bool $preserveHost
     * @return ServerRequestInterface
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;
        $clone->requestTarget = null;

        if (!$preserveHost || !$clone->hasHeader('Host') || $clone->getHeaderLine('Host') === '') {
            if ($uri->getHost() !== '') {
                $clone->headers->set('Host', $uri->getAuthority());
            }
        }

        return $clone;
    }

    /**
     * @return array
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array $cookies
     * @return ServerRequestInterface
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return array
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array $query
     * @return ServerRequestInterface
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return array
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array $uploadedFiles
     * @return ServerRequestInterface
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return mixed
     */
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * @param $data
     * @return ServerRequestInterface
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be an array, object, or null.');
        }

        $clone = clone $this;
        $clone->parsedBody = $data;
        $clone->syncLegacyFields();
        return $clone;
    }

    /**
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param string $name
     * @param $default
     * @return mixed
     */
    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @param string $name
     * @param $value
     * @return ServerRequestInterface
     */
    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * @param string $name
     * @return ServerRequestInterface
     */
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    /**
     * @return Stream
     */
    public function stream(): Stream
    {
        if (!$this->stream instanceof Stream) {
            throw new RuntimeException('Request stream is not available.');
        }

        return $this->stream;
    }

    /**
     * @param Stream $stream
     * @return $this
     */
    public function withStream(Stream $stream): Request
    {
        $clone = clone $this;
        $clone->stream = $stream;
        return $clone;
    }

    /**
     * @param ServerResponder $responder
     * @return void
     */
    public function bindResponder(ServerResponder $responder): void
    {
        $this->responder = $responder;
    }

    /**
     * @deprecated Return a ResponseInterface from the server handler instead.
     */
    public function respond(mixed $content = null, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->requireResponder()->respond(new Response($statusCode, $withHeaders, (string)($content ?? '')));
    }

    /**
     * @deprecated Return Response::json() from the server handler instead.
     */
    public function respondJson(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $json = is_string($content) ? $content : json_encode($content);
        $this->requireResponder()->respond(Response::json((string)$json, $statusCode, $withHeaders));
    }

    /**
     * @deprecated Return Response::text() from the server handler instead.
     */
    public function respondText(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->requireResponder()->respond(Response::text($content, $statusCode, $withHeaders));
    }

    /**
     * @deprecated Return Response::html() from the server handler instead.
     */
    public function respondHtml(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->requireResponder()->respond(Response::html($content, $statusCode, $withHeaders));
    }

    /**
     * @return ServerResponder
     */
    private function requireResponder(): ServerResponder
    {
        if (!$this->responder instanceof ServerResponder) {
            throw new RuntimeException('Server responder is not available for this request.');
        }

        return $this->responder;
    }

    /**
     * @return void
     */
    private function syncLegacyFields(): void
    {
        $this->GET = $this->queryParams;
        $this->POST = is_array($this->parsedBody) ? $this->parsedBody : [];
        $this->COOKIE = $this->cookieParams;
        $this->FILES = $this->uploadedFiles;
        $this->SERVER = $this->serverParams;
        $this->CONTENT = (string)$this->body;
        $this->REQUEST = array_merge($this->GET, $this->POST);
    }

    /**
     * @return void
     */
    public function __clone()
    {
        $this->headers = new HeaderMap($this->headers->all());
    }
}
