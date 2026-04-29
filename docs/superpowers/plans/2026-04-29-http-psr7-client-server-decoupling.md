# HTTP PSR-7 Client/Server Decoupling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `Ripple\Net\Http` around PSR-7 message objects so `Request`, `Response`, `Connection`, `Server`, and the new `Client` are semantically separated and share protocol components without mixing message state with network IO.

**Architecture:** `Request`, `Response`, `Uri`, `BodyStream`, and `UploadedFile` become PSR-7-compatible message/value objects. `Protocol` components serialize, parse, and emit HTTP/1.1 bytes. Server-side `Connection` reads from an accepted `Ripple\Stream`, dispatches a `ServerRequestInterface`, and emits a returned `ResponseInterface`; Client-side `Client` uses a `Connector`, `RequestSerializer`, and `ResponseParser` to implement `sendRequest(RequestInterface): ResponseInterface`.

**Tech Stack:** PHP 8.1, `psr/http-message`, existing `Ripple\Stream`, PHPUnit 9.6, current coroutine/event runtime.

---

## File Structure

- Create `src/Net/Http/BodyStream.php`: PSR-7 `StreamInterface` wrapper around PHP stream resources and strings.
- Create `src/Net/Http/Uri.php`: PSR-7 `UriInterface` implementation.
- Create `src/Net/Http/HeaderMap.php`: internal immutable-friendly header normalization helper.
- Create `src/Net/Http/MessageTrait.php`: shared PSR-7 message behavior for headers, protocol version, and body.
- Modify `src/Net/Http/Response.php`: implement `ResponseInterface`, make PSR `with*()` methods immutable, retain deprecated mutable convenience methods only where existing code depends on them.
- Modify `src/Net/Http/Request.php`: implement `ServerRequestInterface`, expose legacy superglobal-style properties as read-only compatibility data, keep deprecated `respond*()` methods through a responder binding that is not part of PSR.
- Create `src/Net/Http/UploadedFile.php`: PSR-7 `UploadedFileInterface` for parsed multipart uploads.
- Create `src/Net/Http/Protocol/RequestSerializer.php`: serialize `RequestInterface` to HTTP/1.1 request bytes.
- Create `src/Net/Http/Protocol/ResponseParser.php`: parse HTTP/1.1 response bytes into `Response`.
- Create `src/Net/Http/Protocol/ResponseEmitter.php`: write `ResponseInterface` to `Ripple\Stream` for server responses.
- Modify `src/Net/Http/Parser/RequestParser.php`: return `Request` objects instead of raw arrays while preserving existing parsing behavior.
- Modify `src/Net/Http/Connection.php`: depend on dispatcher + emitter, not `Server`; support returned response and deprecated `respond*()` path.
- Modify `src/Net/Http/Server.php`: accept sockets, create `Connection`, and dispatch returned responses through the coroutine pool.
- Create `src/Net/Http/Client/Connector.php`: establish TCP/TLS client streams with connect timeout.
- Create `src/Net/Http/Client/Client.php`: PSR-18-style core with `sendRequest(RequestInterface): ResponseInterface` plus `request/get/post/...` convenience methods.
- Modify `src/Net/Http.php`: expose `Http::client(array $config = []): Client`.
- Add focused tests under `tests/Net/Http/`.

## Task 1: PSR-7 BodyStream

**Files:**
- Create: `src/Net/Http/BodyStream.php`
- Test: `tests/Net/Http/BodyStreamTest.php`

- [ ] **Step 1: Write failing tests for string and resource stream behavior**

Create `tests/Net/Http/BodyStreamTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;

use function fopen;
use function fwrite;
use function rewind;

final class BodyStreamTest extends TestCase
{
    public function testCreatesReadableSeekableStreamFromString(): void
    {
        $stream = BodyStream::fromString('hello');

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertSame(5, $stream->getSize());
        self::assertSame('hello', (string) $stream);
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isSeekable());
        $stream->rewind();
        self::assertSame('he', $stream->read(2));
        $stream->rewind();
        self::assertSame('hello', $stream->getContents());
    }

    public function testWrapsWritableResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);

        fwrite($resource, 'abc');
        rewind($resource);

        $stream = new BodyStream($resource);
        self::assertTrue($stream->isWritable());
        self::assertSame('abc', $stream->getContents());
        self::assertSame(3, $stream->write('def'));
        $stream->rewind();
        self::assertSame('abcdef', $stream->getContents());
    }

    public function testDetachMakesStreamUnusable(): void
    {
        $stream = BodyStream::fromString('body');
        $detached = $stream->detach();

        self::assertIsResource($detached);
        self::assertFalse($stream->isReadable());
        self::assertSame(null, $stream->getSize());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/BodyStreamTest.php
```

Expected: FAIL with `Class "Ripple\Net\Http\BodyStream" not found`.

- [ ] **Step 3: Implement `BodyStream`**

Create `src/Net/Http/BodyStream.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function get_resource_type;
use function is_resource;
use function rewind;
use function stream_get_contents;
use function stream_get_meta_data;

use const SEEK_SET;

final class BodyStream implements StreamInterface
{
    /** @var resource|null */
    private mixed $resource;

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+');
        if (!is_resource($resource)) {
            throw new RuntimeException('Unable to create temporary body stream.');
        }

        fwrite($resource, $content);
        rewind($resource);
        return new self($resource);
    }

    /**
     * @param resource $resource
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource)) {
            throw new RuntimeException('BodyStream expects a PHP stream resource.');
        }

        $this->resource = $resource;
    }

    public function __toString(): string
    {
        if (!$this->resource) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource) {
            fclose($this->resource);
        }
        $this->resource = null;
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if (!$this->resource) {
            return null;
        }

        $stats = fstat($this->resource);
        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        $this->assertAttached();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }
        return $position;
    }

    public function eof(): bool
    {
        return !$this->resource || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->resource && (($this->getMetadata('seekable') ?? false) === true);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();
        if (!$this->isSeekable() || fseek($this->resource, $offset, $whence) !== 0) {
            throw new RuntimeException('Unable to seek stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = (string) ($this->getMetadata('mode') ?? '');
        return $this->resource && strpbrk($mode, 'waxc+') !== false;
    }

    public function write(string $string): int
    {
        $this->assertAttached();
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream is not writable.');
        }

        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new RuntimeException('Unable to write stream.');
        }
        return $written;
    }

    public function isReadable(): bool
    {
        $mode = (string) ($this->getMetadata('mode') ?? '');
        return $this->resource && strpbrk($mode, 'r+') !== false;
    }

    public function read(int $length): string
    {
        $this->assertAttached();
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable.');
        }

        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException('Unable to read stream.');
        }
        return $data;
    }

    public function getContents(): string
    {
        $this->assertAttached();
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }
        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if (!$this->resource) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);
        if ($key === null) {
            $metadata['resource_type'] = get_resource_type($this->resource);
            return $metadata;
        }
        return $metadata[$key] ?? null;
    }

    private function assertAttached(): void
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/BodyStreamTest.php
```

Expected: PASS, `OK (3 tests`.

- [ ] **Step 5: Commit**

```bash
git add src/Net/Http/BodyStream.php tests/Net/Http/BodyStreamTest.php
git commit -m "feat(http): add psr body stream"
```

## Task 2: PSR-7 Uri

**Files:**
- Create: `src/Net/Http/Uri.php`
- Test: `tests/Net/Http/UriTest.php`

- [ ] **Step 1: Write failing Uri tests**

Create `tests/Net/Http/UriTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Ripple\Net\Http\Uri;

final class UriTest extends TestCase
{
    public function testParsesAndRendersUri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8443/api?q=1#frag');

        self::assertInstanceOf(UriInterface::class, $uri);
        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/api', $uri->getPath());
        self::assertSame('q=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
        self::assertSame('https://user:pass@example.com:8443/api?q=1#frag', (string) $uri);
    }

    public function testWithMethodsAreImmutable(): void
    {
        $uri = new Uri('http://example.com/a');
        $changed = $uri->withScheme('https')->withPath('/b')->withQuery('x=1');

        self::assertSame('http://example.com/a', (string) $uri);
        self::assertSame('https://example.com/b?x=1', (string) $changed);
    }

    public function testDefaultPortsAreOmittedFromString(): void
    {
        self::assertSame('http://example.com/', (string) new Uri('http://example.com:80/'));
        self::assertSame('https://example.com/', (string) new Uri('https://example.com:443/'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/UriTest.php
```

Expected: FAIL with `Class "Ripple\Net\Http\Uri" not found`.

- [ ] **Step 3: Implement `Uri`**

Create `src/Net/Http/Uri.php` with a PSR-7 `UriInterface` value object. Use `parse_url()` in the constructor, lowercase scheme and host, make all `with*()` methods clone, and omit default ports in `__toString()`.

Required public signatures:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    public function __construct(string $uri = '') {}
    public function getScheme(): string {}
    public function getAuthority(): string {}
    public function getUserInfo(): string {}
    public function getHost(): string {}
    public function getPort(): ?int {}
    public function getPath(): string {}
    public function getQuery(): string {}
    public function getFragment(): string {}
    public function withScheme(string $scheme): UriInterface {}
    public function withUserInfo(string $user, ?string $password = null): UriInterface {}
    public function withHost(string $host): UriInterface {}
    public function withPort(?int $port): UriInterface {}
    public function withPath(string $path): UriInterface {}
    public function withQuery(string $query): UriInterface {}
    public function withFragment(string $fragment): UriInterface {}
    public function __toString(): string {}
}
```

Implementation details:

```php
private function isDefaultPort(): bool
{
    return ($this->scheme === 'http' && $this->port === 80)
        || ($this->scheme === 'https' && $this->port === 443);
}

private function assertValidPort(?int $port): void
{
    if ($port !== null && ($port < 1 || $port > 65535)) {
        throw new InvalidArgumentException('Invalid URI port.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/UriTest.php
```

Expected: PASS, `OK (3 tests`.

- [ ] **Step 5: Commit**

```bash
git add src/Net/Http/Uri.php tests/Net/Http/UriTest.php
git commit -m "feat(http): add psr uri"
```

## Task 3: HeaderMap And Shared Message Behavior

**Files:**
- Create: `src/Net/Http/HeaderMap.php`
- Create: `src/Net/Http/MessageTrait.php`
- Test: `tests/Net/Http/MessageHeadersTest.php`

- [ ] **Step 1: Write failing tests for PSR header semantics**

Create `tests/Net/Http/MessageHeadersTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Response;

final class MessageHeadersTest extends TestCase
{
    public function testHeadersAreCaseInsensitiveAndImmutable(): void
    {
        $response = new Response();
        $changed = $response->withHeader('Content-Type', 'text/plain');
        $added = $changed->withAddedHeader('content-type', 'charset=utf-8');

        self::assertFalse($response->hasHeader('content-type'));
        self::assertSame(['text/plain'], $changed->getHeader('content-type'));
        self::assertSame(['text/plain', 'charset=utf-8'], $added->getHeader('CONTENT-TYPE'));
        self::assertSame('text/plain, charset=utf-8', $added->getHeaderLine('Content-Type'));
    }

    public function testBodyAndProtocolVersionAreImmutable(): void
    {
        $response = new Response();
        $body = BodyStream::fromString('payload');
        $changed = $response->withProtocolVersion('1.0')->withBody($body);

        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('1.0', $changed->getProtocolVersion());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('payload', (string) $changed->getBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/MessageHeadersTest.php
```

Expected: FAIL because `Response` does not implement PSR message methods yet.

- [ ] **Step 3: Implement `HeaderMap` and `MessageTrait`**

Create `src/Net/Http/HeaderMap.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;

use function array_map;
use function implode;
use function is_array;
use function preg_match;
use function strtolower;

final class HeaderMap
{
    /** @var array<string,array{name:string,values:list<string>}> */
    private array $headers = [];

    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->set((string) $name, $value);
        }
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->headers as $header) {
            $result[$header['name']] = $header['values'];
        }
        return $result;
    }

    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function get(string $name): array
    {
        return $this->headers[strtolower($name)]['values'] ?? [];
    }

    public function line(string $name): string
    {
        return implode(', ', $this->get($name));
    }

    public function set(string $name, mixed $value): void
    {
        $this->assertName($name);
        $this->headers[strtolower($name)] = [
            'name' => $name,
            'values' => $this->normalizeValues($value),
        ];
    }

    public function add(string $name, mixed $value): void
    {
        $this->assertName($name);
        $key = strtolower($name);
        $values = $this->normalizeValues($value);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = ['name' => $name, 'values' => $values];
            return;
        }
        array_push($this->headers[$key]['values'], ...$values);
    }

    public function remove(string $name): void
    {
        unset($this->headers[strtolower($name)]);
    }

    private function normalizeValues(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_map(static fn (mixed $item): string => (string) $item, $values);
    }

    private function assertName(string $name): void
    {
        if ($name === '' || !preg_match('/^[A-Za-z0-9\'`#$%&*+.^_|~!-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid HTTP header name.');
        }
    }
}
```

Create `src/Net/Http/MessageTrait.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Psr\Http\Message\StreamInterface;

trait MessageTrait
{
    private string $protocolVersion = '1.1';
    private HeaderMap $headers;
    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers->line($name);
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers->set($name, $value);
        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers->add($name, $value);
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $clone->headers->remove($name);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function __clone()
    {
        $this->headers = new HeaderMap($this->headers->all());
    }
}
```

- [ ] **Step 4: Update `Response` constructor enough for shared message tests**

Modify `src/Net/Http/Response.php` so it imports `Psr\Http\Message\ResponseInterface`, `Psr\Http\Message\StreamInterface`, uses `MessageTrait`, initializes `$this->headers` and `$this->body`, and implements at least:

```php
public function __construct(int $statusCode = 200, array $headers = [], StreamInterface|string|null $body = null, string $reasonPhrase = '')
{
    $this->headers = new HeaderMap($headers);
    $this->body = $body instanceof StreamInterface ? $body : BodyStream::fromString((string) ($body ?? ''));
    $this->statusCode = $statusCode;
    $this->reasonPhrase = $reasonPhrase;
}
```

Keep existing non-PSR methods compiling until Task 4 replaces them cleanly.

- [ ] **Step 5: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/MessageHeadersTest.php
```

Expected: PASS, `OK (2 tests`.

- [ ] **Step 6: Commit**

```bash
git add src/Net/Http/HeaderMap.php src/Net/Http/MessageTrait.php src/Net/Http/Response.php tests/Net/Http/MessageHeadersTest.php
git commit -m "feat(http): add psr message header behavior"
```

## Task 4: PSR-7 Response

**Files:**
- Modify: `src/Net/Http/Response.php`
- Test: `tests/Net/Http/ResponsePsrTest.php`

- [ ] **Step 1: Write failing Response tests**

Create `tests/Net/Http/ResponsePsrTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\Response;

final class ResponsePsrTest extends TestCase
{
    public function testResponseImplementsPsrResponseInterface(): void
    {
        $response = new Response(201, ['X-Test' => 'yes'], 'created', 'Created');

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('yes', $response->getHeaderLine('x-test'));
        self::assertSame('created', (string) $response->getBody());
    }

    public function testStatusWithMethodIsImmutable(): void
    {
        $response = new Response();
        $changed = $response->withStatus(404, 'Not Found');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(404, $changed->getStatusCode());
        self::assertSame('Not Found', $changed->getReasonPhrase());
    }

    public function testFactoriesPreserveContentLength(): void
    {
        $response = Response::text('ok', 202);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('2', $response->getHeaderLine('Content-Length'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ResponsePsrTest.php
```

Expected: FAIL because `ResponseInterface` methods and `Response::text()` are incomplete.

- [ ] **Step 3: Finish `Response` as PSR-7 object**

Replace `src/Net/Http/Response.php` with a focused PSR implementation. Required public API:

```php
final class Response implements ResponseInterface
{
    use MessageTrait;

    private int $statusCode = 200;
    private string $reasonPhrase = 'OK';

    public function __construct(int $statusCode = 200, array $headers = [], StreamInterface|string|null $body = null, string $reasonPhrase = '') {}
    public static function text(string $content, int $status = 200, array $headers = []): self {}
    public static function html(string $content, int $status = 200, array $headers = []): self {}
    public static function json(mixed $content, int $status = 200, array $headers = []): self {}
    public function getStatusCode(): int {}
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface {}
    public function getReasonPhrase(): string {}
}
```

Implementation rules:

```php
private static function withContentLength(self $response, string $content): self
{
    return $response->withHeader('Content-Length', (string) strlen($content));
}

public static function json(mixed $content, int $status = 200, array $headers = []): self
{
    $encoded = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response = new self($status, ['Content-Type' => 'application/json'] + $headers, (string) $encoded);
    return self::withContentLength($response, (string) $encoded);
}
```

Use `Ripple\Net\Http\Enum\Status::from($code)->text()` when available; otherwise default to an empty reason phrase for unknown valid codes.

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/MessageHeadersTest.php tests/Net/Http/ResponsePsrTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Net/Http/Response.php tests/Net/Http/ResponsePsrTest.php
git commit -m "feat(http): make response psr compatible"
```

## Task 5: PSR-7 UploadedFile And ServerRequest

**Files:**
- Create: `src/Net/Http/UploadedFile.php`
- Modify: `src/Net/Http/Request.php`
- Test: `tests/Net/Http/ServerRequestPsrTest.php`

- [ ] **Step 1: Write failing ServerRequest tests**

Create `tests/Net/Http/ServerRequestPsrTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

final class ServerRequestPsrTest extends TestCase
{
    public function testRequestImplementsServerRequestInterface(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/path?a=1'),
            headers: ['Content-Type' => 'application/json'],
            body: BodyStream::fromString('{"ok":true}'),
            queryParams: ['a' => '1'],
            parsedBody: ['ok' => true],
            cookieParams: ['sid' => 'abc'],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/path?a=1', $request->getRequestTarget());
        self::assertSame(['a' => '1'], $request->getQueryParams());
        self::assertSame(['ok' => true], $request->getParsedBody());
        self::assertSame(['sid' => 'abc'], $request->getCookieParams());
        self::assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testWithMethodsAreImmutable(): void
    {
        $request = new Request(method: 'GET', uri: new Uri('http://example.com/old'));
        $changed = $request->withMethod('PUT')
            ->withUri(new Uri('https://api.example.com/new?x=1'))
            ->withAttribute('route', 'home');

        self::assertSame('GET', $request->getMethod());
        self::assertSame('PUT', $changed->getMethod());
        self::assertSame('/new?x=1', $changed->getRequestTarget());
        self::assertSame(null, $request->getAttribute('route'));
        self::assertSame('home', $changed->getAttribute('route'));
    }

    public function testLegacyReadonlyArraysMirrorPsrState(): void
    {
        $request = new Request(
            method: 'GET',
            uri: new Uri('http://example.com/?a=1'),
            queryParams: ['a' => '1'],
            parsedBody: ['b' => '2'],
            cookieParams: ['c' => '3'],
            uploadedFiles: ['file' => []],
            serverParams: ['REQUEST_METHOD' => 'GET'],
            body: BodyStream::fromString('body'),
        );

        self::assertSame(['a' => '1'], $request->GET);
        self::assertSame(['b' => '2'], $request->POST);
        self::assertSame(['c' => '3'], $request->COOKIE);
        self::assertSame(['file' => []], $request->FILES);
        self::assertSame(['REQUEST_METHOD' => 'GET'], $request->SERVER);
        self::assertSame('body', $request->CONTENT);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ServerRequestPsrTest.php
```

Expected: FAIL because `Request` constructor and PSR methods are not implemented.

- [ ] **Step 3: Implement `UploadedFile`**

Create `src/Net/Http/UploadedFile.php` implementing `UploadedFileInterface`. It must store `StreamInterface $stream`, `?int $size`, `int $error`, `?string $clientFilename`, and `?string $clientMediaType`. `moveTo(string $targetPath)` should write the stream contents to `$targetPath` and throw `RuntimeException` on failure.

Required signatures:

```php
final class UploadedFile implements UploadedFileInterface
{
    public function __construct(StreamInterface $stream, ?int $size, int $error, ?string $clientFilename = null, ?string $clientMediaType = null) {}
    public function getStream(): StreamInterface {}
    public function moveTo(string $targetPath): void {}
    public function getSize(): ?int {}
    public function getError(): int {}
    public function getClientFilename(): ?string {}
    public function getClientMediaType(): ?string {}
}
```

- [ ] **Step 4: Replace `Request` with PSR-7 `ServerRequestInterface` implementation**

Modify `src/Net/Http/Request.php` to use `MessageTrait` and expose:

```php
final class Request implements ServerRequestInterface
{
    use MessageTrait;

    public readonly array $GET;
    public readonly array $POST;
    public readonly array $COOKIE;
    public readonly array $FILES;
    public readonly array $SERVER;
    public readonly mixed $CONTENT;
    public readonly array $REQUEST;

    public function __construct(
        string $method = 'GET',
        UriInterface|string|null $uri = null,
        array $headers = [],
        StreamInterface|string|null $body = null,
        array $serverParams = [],
        array $queryParams = [],
        array $parsedBody = [],
        array $cookieParams = [],
        array $uploadedFiles = [],
        array $attributes = [],
        ?string $requestTarget = null,
    ) {}
}
```

Required PSR methods: `getRequestTarget`, `withRequestTarget`, `getMethod`, `withMethod`, `getUri`, `withUri`, `getServerParams`, `getCookieParams`, `withCookieParams`, `getQueryParams`, `withQueryParams`, `getUploadedFiles`, `withUploadedFiles`, `getParsedBody`, `withParsedBody`, `getAttributes`, `getAttribute`, `withAttribute`, `withoutAttribute`.

Compatibility rules:

```php
$this->GET = $queryParams;
$this->POST = is_array($parsedBody) ? $parsedBody : [];
$this->COOKIE = $cookieParams;
$this->FILES = $uploadedFiles;
$this->SERVER = $serverParams;
$this->CONTENT = (string) $this->body;
$this->REQUEST = array_merge($this->GET, $this->POST);
```

- [ ] **Step 5: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ServerRequestPsrTest.php
```

Expected: PASS, `OK (3 tests`.

- [ ] **Step 6: Commit**

```bash
git add src/Net/Http/UploadedFile.php src/Net/Http/Request.php tests/Net/Http/ServerRequestPsrTest.php
git commit -m "feat(http): make request psr server request compatible"
```

## Task 6: Protocol Serialization, Response Parsing, And Emission

**Files:**
- Create: `src/Net/Http/Protocol/RequestSerializer.php`
- Create: `src/Net/Http/Protocol/ResponseParser.php`
- Create: `src/Net/Http/Protocol/ResponseEmitter.php`
- Test: `tests/Net/Http/ProtocolTest.php`

- [ ] **Step 1: Write failing protocol tests**

Create `tests/Net/Http/ProtocolTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use Ripple\Stream;

use function fopen;
use function rewind;
use function stream_get_contents;

final class ProtocolTest extends TestCase
{
    public function testSerializesRequestInterface(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/api?q=1'),
            headers: ['Content-Type' => 'text/plain'],
            body: BodyStream::fromString('hello'),
        );

        $bytes = (new RequestSerializer())->serialize($request);

        self::assertStringStartsWith("POST /api?q=1 HTTP/1.1\r\n", $bytes);
        self::assertStringContainsString("Host: example.com\r\n", $bytes);
        self::assertStringContainsString("Content-Length: 5\r\n", $bytes);
        self::assertStringEndsWith("\r\n\r\nhello", $bytes);
    }

    public function testParsesFixedLengthResponse(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push("HTTP/1.1 201 Created\r\nContent-Length: 2\r\nX-Test: ok\r\n\r\nhi");

        self::assertCount(1, $responses);
        self::assertSame(201, $responses[0]->getStatusCode());
        self::assertSame('Created', $responses[0]->getReasonPhrase());
        self::assertSame('ok', $responses[0]->getHeaderLine('X-Test'));
        self::assertSame('hi', (string) $responses[0]->getBody());
    }

    public function testParsesChunkedResponse(): void
    {
        $parser = new ResponseParser();
        $responses = $parser->push("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nhi\r\n0\r\n\r\n");

        self::assertCount(1, $responses);
        self::assertSame('hi', (string) $responses[0]->getBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ProtocolTest.php
```

Expected: FAIL because protocol classes do not exist.

- [ ] **Step 3: Implement `RequestSerializer`**

Create `src/Net/Http/Protocol/RequestSerializer.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\RequestInterface;

final class RequestSerializer
{
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

        $body = (string) $request->getBody();
        if ($body !== '' && !$request->hasHeader('Content-Length')) {
            $headers['Content-Length'] = [(string) strlen($body)];
        }

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        return $bytes . "\r\n" . $body;
    }
}
```

- [ ] **Step 4: Implement `ResponseParser`**

Create `src/Net/Http/Protocol/ResponseParser.php`. It should keep an internal buffer, parse status line, headers, fixed `Content-Length`, and `Transfer-Encoding: chunked`, and return `list<Response>`.

Minimum complete method shape:

```php
final class ResponseParser
{
    private string $buffer = '';

    /** @return list<Response> */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $responses = [];
        while (($response = $this->tryParseOne()) instanceof Response) {
            $responses[] = $response;
        }
        return $responses;
    }
}
```

When parsing chunked content, decode chunks into a plain body string and remove the `Transfer-Encoding` header from the final `Response`.

- [ ] **Step 5: Implement `ResponseEmitter`**

Create `src/Net/Http/Protocol/ResponseEmitter.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Psr\Http\Message\ResponseInterface;
use Ripple\Stream;

final class ResponseEmitter
{
    public function emit(ResponseInterface $response, Stream $stream): void
    {
        $bytes = sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        $body = (string) $response->getBody();
        $headers = $response->getHeaders();
        if ($body !== '' && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = [(string) strlen($body)];
        }

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        $stream->writeAll($bytes . "\r\n" . $body);
    }
}
```

- [ ] **Step 6: Run protocol tests**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ProtocolTest.php
```

Expected: PASS, `OK (3 tests`.

- [ ] **Step 7: Commit**

```bash
git add src/Net/Http/Protocol tests/Net/Http/ProtocolTest.php
git commit -m "feat(http): add http protocol codecs"
```

## Task 7: Server Request Parsing And Connection Integration

**Files:**
- Modify: `src/Net/Http/Parser/RequestParser.php`
- Modify: `src/Net/Http/Connection.php`
- Modify: `src/Net/Http/Server.php`
- Test: `tests/Net/Http/RequestParserTest.php`
- Test: `tests/Net/Http/ConnectionDecouplingTest.php`
- Create: `tests/Net/Http/ServerConnectionPsrTest.php`

- [ ] **Step 1: Update parser tests for `Request` objects**

Modify `tests/Net/Http/RequestParserTest.php` assertions so parser results are `Request` objects:

```php
$requests = $parser->push($raw);
self::assertCount(1, $requests);
$request = $requests[0];
self::assertInstanceOf(\Ripple\Net\Http\Request::class, $request);
self::assertSame('GET', $request->getMethod());
self::assertSame('/path?a=1', $request->getRequestTarget());
self::assertSame(['a' => '1'], $request->getQueryParams());
self::assertSame('value', $request->getHeaderLine('Header-Name'));
```

- [ ] **Step 2: Add connection returned-response test**

Create `tests/Net/Http/ServerConnectionPsrTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ripple\Net\Http\Connection;
use Ripple\Net\Http\Response;
use Ripple\Stream;

use function fopen;
use function rewind;
use function stream_get_contents;
use function fwrite;

final class ServerConnectionPsrTest extends TestCase
{
    public function testConnectionDoesNotRequireServerAndDispatchesPsrRequest(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, "GET /hello HTTP/1.1\r\nHost: example.com\r\n\r\n");
        rewind($resource);

        $seen = null;
        $connection = new Connection(
            new Stream($resource),
            function (ServerRequestInterface $request) use (&$seen): Response {
                $seen = $request;
                return Response::text('ok');
            }
        );

        foreach ($connection->fillForTest("GET /hello HTTP/1.1\r\nHost: example.com\r\n\r\n") as $response) {
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertInstanceOf(ServerRequestInterface::class, $seen);
    }
}
```

`fillForTest()` is a narrow test seam added in Step 4; it must call the same parser + dispatch path as `start()` without registering event watchers.

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/RequestParserTest.php tests/Net/Http/ConnectionDecouplingTest.php tests/Net/Http/ServerConnectionPsrTest.php
```

Expected: FAIL because parser still returns arrays and `fillForTest()` does not exist.

- [ ] **Step 4: Modify `RequestParser` to create `Request` objects**

In `completeRequest()`, replace raw array return with:

```php
$headers = [];
foreach ($this->meta as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[str_replace('_', '-', substr($key, 5))] = $value;
    }
}

$uri = new Uri(($this->meta['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://');
$host = $this->meta['HTTP_HOST'] ?? 'localhost';
$target = $this->meta['REQUEST_URI'] ?? '/';
$uri = new Uri('http://' . $host . $target);

$request = new Request(
    method: $this->method->value,
    uri: $uri,
    headers: $headers,
    body: BodyStream::fromString((string) $this->content),
    serverParams: $this->meta,
    queryParams: $this->get,
    parsedBody: $this->post,
    cookieParams: $this->cookies,
    uploadedFiles: $this->files,
    requestTarget: $target,
);
```

Return the `Request` object, reset parser state, and update docblocks from arrays to `list<Request>`.

- [ ] **Step 5: Modify `Connection` to dispatch PSR requests and emit returned responses**

Change constructor to:

```php
public function __construct(
    public readonly Stream $stream,
    callable $dispatcher,
    private array $meta = [],
    private readonly ResponseEmitter $emitter = new ResponseEmitter(),
) {}
```

Change request handling to:

```php
private function onRequest(Request $request): ?ResponseInterface
{
    $response = ($this->dispatcher)($request);
    if ($response instanceof ResponseInterface) {
        $response = $this->applyConnectionHeaders($request, $response);
        $this->emitter->emit($response, $this->stream);
        return $response;
    }
    return null;
}
```

Add:

```php
/** @return list<ResponseInterface> */
public function fillForTest(string $content): array
{
    $responses = [];
    foreach ($this->fill($content) as $request) {
        $response = $this->onRequest($request);
        if ($response instanceof ResponseInterface) {
            $responses[] = $response;
        }
    }
    return $responses;
}
```

- [ ] **Step 6: Modify `Server` dispatcher to accept returned responses**

Keep the coroutine pool, but make the dispatcher return the handler result:

```php
$dispatcher = function (Request $request): ?ResponseInterface {
    return Scheduler::resume($this->acquireCoroutine(), $request)->rethrow();
};
```

If existing `HotCoroutinePool` callback discards return values, update the callback so `call_user_func($this->onRequest, suspend())` is returned.

- [ ] **Step 7: Run tests**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/RequestParserTest.php tests/Net/Http/ConnectionDecouplingTest.php tests/Net/Http/ServerConnectionPsrTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Net/Http/Parser/RequestParser.php src/Net/Http/Connection.php src/Net/Http/Server.php tests/Net/Http/RequestParserTest.php tests/Net/Http/ConnectionDecouplingTest.php tests/Net/Http/ServerConnectionPsrTest.php
git commit -m "feat(http): dispatch psr server requests"
```

## Task 8: Deprecated Server Response Convenience Layer

**Files:**
- Create: `src/Net/Http/ServerResponder.php`
- Modify: `src/Net/Http/Request.php`
- Modify: `src/Net/Http/Connection.php`
- Test: `tests/Net/Http/DeprecatedRespondCompatibilityTest.php`

- [ ] **Step 1: Write compatibility test**

Create `tests/Net/Http/DeprecatedRespondCompatibilityTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Connection;
use Ripple\Stream;

use function fopen;

final class DeprecatedRespondCompatibilityTest extends TestCase
{
    public function testDeprecatedRespondJsonStillEmitsResponse(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        $connection = new Connection($stream, function ($request): void {
            $request->respondJson(['ok' => true], [], 201);
        });

        $responses = $connection->fillForTest("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");

        self::assertCount(1, $responses);
        self::assertSame(201, $responses[0]->getStatusCode());
        self::assertSame('application/json', $responses[0]->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', (string) $responses[0]->getBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/DeprecatedRespondCompatibilityTest.php
```

Expected: FAIL because `respondJson()` no longer emits through a responder.

- [ ] **Step 3: Implement `ServerResponder`**

Create `src/Net/Http/ServerResponder.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Psr\Http\Message\ResponseInterface;

final class ServerResponder
{
    private ?ResponseInterface $response = null;

    public function respond(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    public function response(): ?ResponseInterface
    {
        return $this->response;
    }
}
```

- [ ] **Step 4: Add deprecated respond methods to `Request`**

Add a nullable responder property and these methods:

```php
private ?ServerResponder $responder = null;

public function bindResponder(ServerResponder $responder): void
{
    $this->responder = $responder;
}

/** @deprecated Return a ResponseInterface from the server handler instead. */
public function respond(mixed $content = null, array $withHeaders = [], int $statusCode = 200): void
{
    $response = new Response($statusCode, $withHeaders, (string) ($content ?? ''));
    $this->requireResponder()->respond($response);
}

/** @deprecated Return Response::json() from the server handler instead. */
public function respondJson(mixed $content, array $withHeaders = [], int $statusCode = 200): void
{
    $this->requireResponder()->respond(Response::json($content, $statusCode, $withHeaders));
}

/** @deprecated Return Response::text() from the server handler instead. */
public function respondText(string $content, array $withHeaders = [], int $statusCode = 200): void
{
    $this->requireResponder()->respond(Response::text($content, $statusCode, $withHeaders));
}

/** @deprecated Return Response::html() from the server handler instead. */
public function respondHtml(string $content, array $withHeaders = [], int $statusCode = 200): void
{
    $this->requireResponder()->respond(Response::html($content, $statusCode, $withHeaders));
}

private function requireResponder(): ServerResponder
{
    if (!$this->responder) {
        throw new \RuntimeException('Server responder is not available for this request.');
    }
    return $this->responder;
}
```

- [ ] **Step 5: Bind responder in `Connection`**

Before dispatching:

```php
$responder = new ServerResponder();
$request->bindResponder($responder);
$returned = ($this->dispatcher)($request);
$response = $returned instanceof ResponseInterface ? $returned : $responder->response();
```

If neither a returned response nor a deprecated responder response exists, emit no response and let the connection continue; this preserves streaming/WebSocket handlers that take over the underlying stream.

- [ ] **Step 6: Run compatibility test**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/DeprecatedRespondCompatibilityTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Net/Http/ServerResponder.php src/Net/Http/Request.php src/Net/Http/Connection.php tests/Net/Http/DeprecatedRespondCompatibilityTest.php
git commit -m "feat(http): preserve deprecated respond helpers"
```

## Task 9: PSR-18-Style Client Core

**Files:**
- Create: `src/Net/Http/Client/Connector.php`
- Create: `src/Net/Http/Client/Client.php`
- Modify: `src/Net/Http.php`
- Test: `tests/Net/Http/ClientTest.php`

- [ ] **Step 1: Write client tests with a fake connector**

Create `tests/Net/Http/ClientTest.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Client\Client;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Uri;

final class ClientTest extends TestCase
{
    public function testSendRequestSerializesRequestAndReturnsParsedResponse(): void
    {
        $client = new Client([
            'connector' => static fn (): object => new class {
                public string $written = '';
                public function writeAll(string $bytes): int { $this->written .= $bytes; return strlen($bytes); }
                public function read(int $length): string { return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"; }
                public function close(): void {}
            },
        ]);

        $response = $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testConvenienceGetBuildsRequest(): void
    {
        $client = new Client([
            'sender' => static fn (Request $request): Response => Response::text($request->getMethod() . ' ' . $request->getRequestTarget()),
        ]);

        $response = $client->get('http://example.com/path?q=1');

        self::assertSame('GET /path?q=1', (string) $response->getBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ClientTest.php
```

Expected: FAIL because `Client\Client` does not exist.

- [ ] **Step 3: Implement `Connector`**

Create `src/Net/Http/Client/Connector.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Psr\Http\Message\UriInterface;
use Ripple\Stream;

final class Connector
{
    public function connect(UriInterface $uri, float $timeout = 10.0): Stream
    {
        $scheme = $uri->getScheme() ?: 'http';
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        $stream = Stream::connect(sprintf('tcp://%s:%d', $host, $port), $timeout);
        if ($scheme === 'https') {
            $stream->enableSSL();
        }
        return $stream;
    }
}
```

- [ ] **Step 4: Implement `Client`**

Create `src/Net/Http/Client/Client.php`:

```php
<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;

final class Client
{
    private Connector $connector;
    private RequestSerializer $serializer;

    public function __construct(private array $config = [])
    {
        $this->connector = $config['connector'] ?? new Connector();
        $this->serializer = $config['serializer'] ?? new RequestSerializer();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (isset($this->config['sender'])) {
            return ($this->config['sender'])($request);
        }

        $stream = ($this->connector instanceof Connector)
            ? $this->connector->connect($request->getUri(), (float) ($this->config['connect_timeout'] ?? 10.0))
            : ($this->connector)($request->getUri(), $this->config);

        try {
            $stream->writeAll($this->serializer->serialize($request));
            $parser = new ResponseParser();
            while (true) {
                $responses = $parser->push($stream->read(8192));
                if ($responses !== []) {
                    return $responses[0];
                }
            }
        } finally {
            $stream->close();
        }
    }

    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        $body = $options['body'] ?? '';
        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        } elseif (isset($options['form_params'])) {
            $body = http_build_query($options['form_params']);
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return $this->sendRequest(new Request(
            method: strtoupper($method),
            uri: new Uri($uri),
            headers: $options['headers'] ?? [],
            body: BodyStream::fromString((string) $body),
        ));
    }

    public function get(string $uri, array $options = []): ResponseInterface { return $this->request('GET', $uri, $options); }
    public function post(string $uri, array $options = []): ResponseInterface { return $this->request('POST', $uri, $options); }
    public function put(string $uri, array $options = []): ResponseInterface { return $this->request('PUT', $uri, $options); }
    public function patch(string $uri, array $options = []): ResponseInterface { return $this->request('PATCH', $uri, $options); }
    public function delete(string $uri, array $options = []): ResponseInterface { return $this->request('DELETE', $uri, $options); }
}
```

- [ ] **Step 5: Expose `Http::client()`**

Modify `src/Net/Http.php`:

```php
use Ripple\Net\Http\Client\Client;

public static function client(array $config = []): Client
{
    return new Client($config);
}
```

- [ ] **Step 6: Run client tests**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http/ClientTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Net/Http/Client src/Net/Http.php tests/Net/Http/ClientTest.php
git commit -m "feat(http): add psr style http client"
```

## Task 10: Existing Examples, WebSocket, And File Transfer Compatibility

**Files:**
- Modify: `examples/11-http-sse.php`
- Modify: `src/Net/WebSocket/Server/Server.php`
- Modify: `tests/Net/HttpFileTransferTest.php`
- Test: existing HTTP/WebSocket/file transfer tests

- [ ] **Step 1: Update example handler style**

Change `examples/11-http-sse.php` normal HTTP paths from:

```php
$request->respond($html, ['Content-Type' => 'text/html; charset=utf-8']);
```

to:

```php
return Response::html($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
```

Keep SSE streaming paths on the deprecated responder or direct stream path until a streaming `ResponseInterface` emitter is added; document with a single comment:

```php
// SSE keeps the connection open and writes to the stream directly.
```

- [ ] **Step 2: Update WebSocket handshake to return responses for failure paths**

In `src/Net/WebSocket/Server/Server.php`, replace failure calls:

```php
$request->respond('Bad Request', [], 400);
return;
```

with:

```php
return Response::text('Bad Request', 400);
```

Keep successful upgrade path taking ownership of the stream and returning `null`.

- [ ] **Step 3: Update file transfer test handlers to return `Response`**

In `tests/Net/HttpFileTransferTest.php`, replace:

```php
$request->respondJson(['ok' => true]);
```

with:

```php
return Response::json(['ok' => true]);
```

For file download:

```php
return (new Response(200, [
    'Content-Type' => 'application/octet-stream',
    'Content-Disposition' => 'attachment; filename="test.txt"',
], $fileStream))->withHeader('Content-Length', (string) $size);
```

- [ ] **Step 4: Run compatibility suite**

Run:

```bash
./vendor/bin/phpunit tests/Net/Http tests/Net/HttpFileTransferTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add examples/11-http-sse.php src/Net/WebSocket/Server/Server.php tests/Net/HttpFileTransferTest.php
git commit -m "refactor(http): migrate handlers toward returned responses"
```

## Task 11: Full Verification And Public API Review

**Files:**
- Modify only if verification exposes compile or test failures.

- [ ] **Step 1: Run all tests**

Run:

```bash
./vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 2: Run syntax check on changed PHP files**

Run:

```bash
git diff --name-only HEAD~10..HEAD -- '*.php' | xargs -n 1 php -l
```

Expected: every file prints `No syntax errors detected`.

- [ ] **Step 3: Inspect public HTTP API references**

Run:

```bash
rg -n "respondJson|respondText|respondHtml|respond\\(|new Response|new Request|Http::client|sendRequest" src examples tests
```

Expected:
- `respond*()` appears only in deprecated compatibility tests or streaming cases that intentionally take over the server stream.
- New server handlers prefer `return Response::...`.
- `Http::client()` and `Client::sendRequest()` references compile.

- [ ] **Step 4: Commit final fixes if any files changed**

If Step 1-3 required fixes:

```bash
git add src tests examples
git commit -m "fix(http): complete psr http integration"
```

If no files changed:

```bash
git status --short
```

Expected: no output.

## Self-Review

Spec coverage:
- Server/Connection decoupling is covered by Tasks 6-8.
- Shared PSR-7 `Request`/`Response` semantics are covered by Tasks 1-5.
- Client embedding through `Connector`, serializer, and parser is covered by Task 9.
- Soft migration for `respond*()` is covered by Task 8 and Task 10.
- Verification is covered by Task 11.

Placeholder scan:
- The plan contains concrete file paths, commands, expected results, and code shapes for every task.
- No task relies on an undefined class without an earlier task defining it.

Type consistency:
- `Request` consistently means `Ripple\Net\Http\Request implements ServerRequestInterface`.
- `Response` consistently means `Ripple\Net\Http\Response implements ResponseInterface`.
- `Connection` accepts `callable(Request): ?ResponseInterface` behavior and emits through `ResponseEmitter`.
- `Client` exposes `sendRequest(RequestInterface): ResponseInterface` and convenience methods returning `ResponseInterface`.
