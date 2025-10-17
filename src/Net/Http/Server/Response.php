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

namespace Ripple\Net\Http\Server;

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
use function array_key_exists;
use function is_int;
use function is_file;

/**
 * response entity
 */
class Response
{
    use ClientResponse;

    /*** @var mixed|Stream */
    protected mixed $body;

    /*** @var array */
    protected array $headers = [];

    /*** @var array */
    protected array $cookieLines = [];

    /*** @var int */
    protected int $statusCode = 200;

    /*** @var string */
    protected string $statusText = 'OK';

    /**
     */
    public function __construct()
    {
    }

    /**
     * @param Stream $stream
     * @return void
     * @throws ConnectionException
     */
    public function __invoke(Stream $stream): void
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

        if (is_string($this->body)) {
            $stream->writeAll("{$header}\r\n{$this->body}");
        } else {
            $stream->writeAll("{$header}\r\n");

            // 普通文本
            if (is_string($this->body)) {
                $stream->writeAll($this->body);
            }

            // 可控流式传输方式
            elseif ($this->body instanceof Generator) {
                foreach ($this->body as $content) {
                    if (!is_string($content) || $content === '') {
                        continue;
                    }
                    $stream->writeAll($content);
                }
            }

            // 固定流传输方式
            elseif ($this->body instanceof Stream) {
                try {
                    $owner = \Co\current();
                    $stream->setWriteBufferMax(10485760);
                    $stream->watchWrite(function () use ($owner, $stream) {
                        try {
                            $bufferLen = $stream->writeBuffer()->length();

                            // 阈值检查
                            if ($bufferLen > 10405760) {
                                $stream->flushOnce();
                                return;
                            }

                            // 优先处理body数据
                            if (!$this->body->isClosed()) {
                                $buf = $this->body->read(8192);
                                if ($buf) {
                                    $stream->writeAsync($buf);
                                }

                                if ($this->body->eof()) {
                                    $this->body->close();
                                }
                            }

                            $stream->flushOnce();

                            // 文件末尾 && 缓冲区空
                            if ($this->body->eof() && $stream->writeBuffer()->length() === 0) {
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
                    $stream->unwatchWrite();
                }
            } else {
                throw new ConnectionException('The response content is illegal.');
            }
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
     * @return $this
     */
    public function setStatusText(string $statusText): static
    {
        $this->statusText = $statusText;
        return $this;
    }

    /**
     * @param int $statusCode
     * @return $this
     */
    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function withHeader(string $name, mixed $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     */
    public function withCookie(string $name, array $values): static
    {
        $this->cookieLines[$name] = $this->buildCookieLine($name, $values);
        return $this;
    }

    /**
     * @return $this
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
     * @param string $name
     * @return $this
     */
    public function removeHeader(string $name): static
    {
        unset($this->headers[$name]);
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

    /**
     * @param string $name
     * @param array $values
     * @return string
     */
    private function buildCookieLine(string $name, array $values): string
    {
        $segments = [];

        $mainValue = $values[$name] ?? $values['value'] ?? null;
        if (array_key_exists($name, $values)) {
            unset($values[$name]);
        }

        if ($mainValue !== null) {
            $segments[] = $name . '=' . $mainValue;
        } else {
            $segments[] = $name;
        }

        foreach ($values as $k => $v) {
            if (is_int($k)) {
                if ($v !== null && $v !== false && $v !== '') {
                    $segments[] = strval($v);
                }
                continue;
            }

            if ($v === null || $v === true) {
                $segments[] = $k;
            } elseif ($v === false) {
                continue;
            } else {
                $segments[] = $k . '=' . $v;
            }
        }

        return implode('; ', $segments);
    }
}
