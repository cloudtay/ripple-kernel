<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Net\Http;

use Closure;
use Generator;
use Ripple\Net\Http\Trait\ClientResponse;
use Ripple\Runtime\Scheduler;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream;
use Throwable;

use function filesize;
use function implode;
use function is_array;
use function is_resource;
use function is_string;
use function strlen;
use function strval;
use function is_file;
use function strtolower;
use function is_callable;
use function gmdate;
use function is_numeric;
use function rawurlencode;
use function str_replace;
use function trim;
use function ucfirst;

/**
 * response entity
 */
class Response
{
    use ClientResponse;

    /*** @var mixed|Stream|Generator|string */
    protected mixed $body;

    /*** @var array */
    protected array $headers = [];

    /*** @var array */
    protected array $cookieLines = [];

    /*** @var int */
    protected int $statusCode = 200;

    /*** @var string */
    protected string $statusText = 'OK';

    /*** @var bool 响应体发送完成后是否关闭连接 */
    protected bool $closeAfterBody = false;

    /**
     * @var Stream|null
     */
    protected ?Stream $stream = null;

    /**
     */
    public function __construct()
    {
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    public function __invoke(): void
    {
        $this->send();
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    public function send(): void
    {
        // respond header
        $header = "HTTP/1.1 {$this->statusCode()} {$this->statusText}\r\n";

        foreach ($this->headers as $name => $values) {
            if (is_string($values)) {
                $header .= "$name: $values\r\n";
            } elseif (is_array($values)) {
                $header .= "$name: " . implode(', ', $values) . "\r\n";
            }
        }

        foreach ($this->cookieLines as $cookieLine) {
            $header .= 'Set-Cookie: ' . $cookieLine . "\r\n";
        }

        // respond body
        if (is_callable($this->body)) {
            $this->body = ($this->body)($this->stream);
        }

        if (is_string($this->body)) {
            $this->stream->writeAll("{$header}\r\n{$this->body}");
        } else {
            $this->stream->writeAll("{$header}\r\n");

            // 普通文本
            if (is_string($this->body)) {
                $this->stream->writeAll($this->body);
            }

            // 可控流式传输方式
            elseif ($this->body instanceof Generator) {
                foreach ($this->body as $content) {
                    if (!is_string($content) || $content === '') {
                        continue;
                    }

                    try {
                        $this->stream->writeAll($content);
                    } catch (Throwable) {
                        break;
                    }
                }
            }

            // 固定流传输方式
            elseif ($this->body instanceof Stream) {
                try {
                    $owner = \Co\current();
                    $this->stream->setWriteBufferMax(10485760);
                    $this->stream->watchWrite(function () use ($owner) {
                        try {
                            $bufferLen = $this->stream->writeBuffer()->length();

                            // 阈值检查
                            if ($bufferLen > 10405760) {
                                $this->stream->flushOnce();
                                return;
                            }

                            // 优先处理body数据
                            if (!$this->body->isClosed()) {
                                $buf = $this->body->read(8192);
                                if ($buf) {
                                    $this->stream->writeAsync($buf);
                                }

                                if ($this->body->eof()) {
                                    $this->body->close();
                                }
                            }

                            $this->stream->flushOnce();

                            // 文件末尾 && 缓冲区空
                            if ($this->body->eof() && $this->stream->writeBuffer()->length() === 0) {
                                Scheduler::tryResume($owner);
                            }
                        } catch (Throwable) {
                            Scheduler::tryResume($owner);
                        }
                    });

                    $owner->suspend();
                } catch (ConnectionException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new ConnectionException($exception->getMessage(), $exception->getCode(), $exception);
                } finally {
                    $this->body->close();
                    $this->stream->unwatchWrite();
                }
            } else {
                throw new ConnectionException('The response content is illegal.');
            }
        }

        $connectionHeader = $this->headers['Connection'] ?? '';
        $connectionValue = strtolower($connectionHeader);

        if ($connectionValue === 'close' || $this->closeAfterBody) {
            $this->stream->close();
        }
    }

    /**
     * @param string|null $name
     * @return mixed
     */
    public function header(null|string $name = null): mixed
    {
        if (!$name) {
            return $this->headers;
        }
        return $this->headers[$name] ?? null;
    }

    /**
     * @return int
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function statusText(): string
    {
        return $this->statusText;
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
     * @param string $statusText
     * @return static
     */
    public function setStatusText(string $statusText): static
    {
        $this->statusText = $statusText;
        return $this;
    }

    /**
     * @param int $statusCode
     * @return static
     */
    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return static
     */
    public function withHeader(string $name, mixed $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     * @param string $content
     * @param array|null $options
     * @return static
     */
    public function withCookie(string $name, string $content, ?array $options = []): static
    {
        $options = $options ?? [];

        $name = trim($name);
        if ($name === '') {
            return $this;
        }

        $value = str_replace([';', "\r", "\n", "\0"], '', rawurlencode($content));

        // 开始收集所有部分
        $parts = ["{$name}={$value}"];

        // expires
        if (isset($options['expires']) && is_numeric($options['expires'])) {
            $exp = (int) $options['expires'];
            if ($exp > 0) {
                $expiresStr = gmdate('D, d M Y H:i:s \G\M\T', $exp);
                $parts[] = "Expires={$expiresStr}";
            }
        }

        // maxAge
        if (isset($options['maxAge']) && is_numeric($options['maxAge'])) {
            $maxAge = (int) $options['maxAge'];
            $parts[] = "Max-Age={$maxAge}";
            if ($maxAge <= 0) {
                $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            }
        }

        // path - 默认 /
        $path = !empty($options['path']) ? trim($options['path']) : '/';
        $path = str_replace([';', "\r", "\n"], '', $path);
        $parts[] = "Path={$path}";

        // domain
        if (!empty($options['domain'])) {
            $domain = trim($options['domain'], '. ');
            if ($domain !== '') {
                $domain = str_replace([';', "\r", "\n"], '', $domain);
                $parts[] = "Domain={$domain}";
            }
        }

        // secure
        if (!empty($options['secure'])) {
            $parts[] = 'Secure';
        }

        // httponly
        if (!empty($options['httponly']) || !empty($options['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }

        // samesite
        if (!empty($options['samesite'])) {
            $s = strtolower(trim($options['samesite']));
            if ($s === 'strict' || $s === 'lax' || $s === 'none') {
                $s = ucfirst($s);
                $parts[] = "SameSite={$s}";
            }
        }

        $this->cookieLines[] = implode('; ', $parts);
        return $this;
    }

    /**
     * @param string $cookieLine
     * @return static
     */
    public function withCookieLine(string $cookieLine): static
    {
        $this->cookieLines[] = $cookieLine;
        return $this;
    }

    /**
     * @param mixed $content
     * @return static
     */
    public function withBody(mixed $content): static
    {
        if (is_string($content)) {
            $this->withHeader('Content-Length', strval(strlen($content)));
        } elseif ($content instanceof Stream) {
            $path = $content->getMetadata('uri');
            if (is_string($path) && $path !== '' && is_file($path)) {
                $length = filesize($path);
                $this->withHeader('Content-Length', strval($length));
            }
            // 不再默认设置 Content-Type/Content-Disposition, 让调用方自行决定是否下载
        } elseif (is_resource($content)) {
            return $this->withBody(new Stream($content));
        } elseif ($content instanceof Closure) {
            return $this->withBody($content());
        }

        $this->body = $content;
        return $this;
    }

    /**
     * @param Stream $stream
     * @return static
     */
    public function withStream(Stream $stream): static
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * @param string $name
     * @return static
     */
    public function removeHeader(string $name): static
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * 响应体发送完成后关闭连接
     * @return static
     */
    public function closeAfter(): static
    {
        $this->closeAfterBody = true;
        return $this;
    }

    /**
     * 获取响应体内容
     * @return mixed
     */
    public function body(): mixed
    {
        return $this->body;
    }
}
