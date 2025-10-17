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

namespace Ripple\Net\Http\Trait;

use Ripple\Coroutine;
use Ripple\Net\Http\Server\Response;
use Ripple\Runtime\Scheduler;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function filesize;
use function is_file;
use function is_string;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function ucwords;
use function sprintf;
use function is_resource;
use function fread;
use function feof;
use function pathinfo;
use function uniqid;
use function json_encode;
use function http_build_query;

use const PATHINFO_EXTENSION;

trait ClientRequest
{
    /**
     * @var Stream|null 当前使用的流
     */
    private ?Stream $currentStream = null;

    /**
     * @var Coroutine|null 当前协程
     */
    private ?Coroutine $owner = null;

    /**
     * @param Stream $stream
     * @return Response
     */
    public function __invoke(Stream $stream): Response
    {
        $this->currentStream = $stream;
        $this->owner = \Co\current();

        try {
            $requestLine = $this->buildRequestLine();
            $headers = $this->buildHeaders();

            $this->writeRequest($requestLine, $headers);
            $this->writeBody();

            $response = new Response();
            $response->fill($stream);

            return $response;
        } finally {
            $this->currentStream = null;
            $this->owner = null;
        }
    }

    /**
     * @return string
     */
    private function buildRequestLine(): string
    {
        $method = $this->SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $this->SERVER['REQUEST_URI'] ?? '/';
        $version = $this->SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        return sprintf("%s %s %s\r\n", $method, $uri, $version);
    }

    /**
     * @return array
     */
    private function buildHeaders(): array
    {
        $headers = [];

        $host = $this->SERVER['HTTP_HOST'] ?? $this->SERVER['SERVER_NAME'] ?? 'localhost';
        $headers['Host'] = $host;
        $headers['Connection'] = 'close';

        foreach ($this->SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', substr($k, 5));
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = $v;
            }
        }

        return $this->processContentLength($headers);
    }

    /**
     * @param array $headers
     * @return array
     */
    private function processContentLength(array $headers): array
    {
        $body = $this->CONTENT;
        if ($body === null) {
            return $headers;
        }

        if (is_string($body)) {
            $headers['Content-Length'] = (string)strlen($body);
        } elseif ($body instanceof Stream) {
            $path = $body->getMetadata('uri');
            if (is_string($path) && $path !== '' && is_file($path)) {
                $headers['Content-Length'] = (string)filesize($path);
            }
        }

        return $headers;
    }

    /**
     * @param string $requestLine
     * @param array $headers
     * @throws ConnectionException
     * @return void
     */
    private function writeRequest(string $requestLine, array $headers): void
    {
        $this->currentStream->writeAll($requestLine);

        foreach ($headers as $name => $value) {
            $this->currentStream->writeAll(sprintf("%s: %s\r\n", $name, $value));
        }

        $this->currentStream->writeAll("\r\n");
    }

    /**
     * 写入请求体,大数据使用流式传输
     * @throws ConnectionException
     * @return void
     */
    private function writeBody(): void
    {
        $body = $this->CONTENT;
        if ($body === null) {
            return;
        }

        if (is_string($body)) {
            $this->writeStringBody($body);
        } elseif ($body instanceof Stream) {
            $this->writeStreamBody($body);
        }
    }

    /**
     * 写入字符串类型的请求体
     * 小于1MB直接发送, 大于1MB使用流式传输
     * @param string $body
     * @throws ConnectionException
     * @return void
     */
    private function writeStringBody(string $body): void
    {
        $len = strlen($body);

        if ($len < 1048576) {
            $this->currentStream->writeAll($body);
            return;
        }

        $stream = $this->currentStream;
        $owner = $this->owner;
        $offset = 0;

        try {
            $stream->setWriteBufferMax(10485760);

            $stream->watchWrite(function () use ($owner, $stream, $body, $len, &$offset) {
                try {
                    $bufferLen = $stream->writeBuffer()->length();

                    // 缓冲区快满了, 先刷新
                    if ($bufferLen > 10405760) {
                        $stream->flushOnce();
                        return;
                    }

                    if ($offset < $len) {
                        $chunk = substr($body, $offset, 8192);
                        $stream->writeAsync($chunk);
                        $offset += strlen($chunk);
                    }

                    $stream->flushOnce();

                    if ($offset >= $len && $stream->writeBuffer()->length() === 0) {
                        Scheduler::tryResume($owner);
                    }
                } catch (Throwable $e) {
                    Scheduler::tryThrow($owner, $e);
                }
            });

            $owner->suspend();
        } finally {
            $stream->unwatchWrite();
        }
    }

    /**
     * 写入流类型的请求体
     * 使用流式传输避免大文件缓冲区溢出
     * @param Stream $body
     * @throws ConnectionException
     * @return void
     */
    private function writeStreamBody(Stream $body): void
    {
        $stream = $this->currentStream;
        $owner = $this->owner;

        try {
            $stream->setWriteBufferMax(10485760);

            $stream->watchWrite(function () use ($owner, $stream, $body) {
                try {
                    $bufferLen = $stream->writeBuffer()->length();

                    // 缓冲区快满了, 先刷新
                    if ($bufferLen > 10405760) {
                        $stream->flushOnce();
                        return;
                    }

                    if (!$body->isClosed()) {
                        $chunk = $body->read(8192);
                        if ($chunk !== '') {
                            $stream->writeAsync($chunk);
                        }

                        if ($body->eof()) {
                            $body->close();
                        }
                    }

                    $stream->flushOnce();

                    if ($body->eof() && $stream->writeBuffer()->length() === 0) {
                        Scheduler::tryResume($owner);
                    }
                } catch (Throwable $e) {
                    Scheduler::tryThrow($owner, $e);
                }
            });

            $owner->suspend();
        } finally {
            $body->close();
            $stream->unwatchWrite();
        }
    }

    /**
     * 准备请求体
     * @param array $options
     * @return array [content, boundary]
     * @throws ConnectionException
     */
    public function prepareRequestBody(array $options): array
    {
        $content = null;
        $boundary = null;

        if (isset($options['body'])) {
            $content = $options['body'];
        } elseif (isset($options['json'])) {
            $content = json_encode($options['json']);
        } elseif (isset($options['form_params'])) {
            $content = http_build_query($options['form_params']);
        } elseif (isset($options['multipart'])) {
            [$content, $boundary] = $this->buildMultipart($options['multipart']);
        }

        return [$content, $boundary];
    }

    /**
     * 构建multipart请求体
     * @param array $multipart 数据块数组
     * @return array [请求体, 边界字符串]
     * @throws ConnectionException
     */
    public function buildMultipart(array $multipart): array
    {
        $boundary = sprintf('----RippleFormBoundary%s', uniqid());
        $body = '';

        foreach ($multipart as $part) {
            $body .= sprintf("--%s\r\n", $boundary);
            $body .= sprintf('Content-Disposition: form-data; name="%s"', $part['name']);

            $filename = $part['filename'] ?? null;
            if ($filename) {
                $body .= sprintf('; filename="%s"', $filename);
            }

            $body .= "\r\n";

            // 自定义头部优先, 否则自动检测
            if (isset($part['headers'])) {
                foreach ($part['headers'] as $name => $value) {
                    $body .= sprintf("%s: %s\r\n", $name, $value);
                }
            } elseif ($filename) {
                $contentType = $this->detectContentType($part['contents'], $filename);
                if ($contentType) {
                    $body .= sprintf("Content-Type: %s\r\n", $contentType);
                }
            }

            $body .= "\r\n";
            $body .= $this->processMultipartContent($part['contents']) . "\r\n";
        }

        $body .= sprintf("--%s--\r\n", $boundary);
        return [$body, $boundary];
    }

    /**
     * 处理multipart内容
     * @param mixed $contents 内容
     * @return string
     * @throws ConnectionException
     */
    public function processMultipartContent(mixed $contents): string
    {
        if (is_resource($contents)) {
            // 处理文件资源
            $data = '';
            while (!feof($contents)) {
                $data .= fread($contents, 8192);
            }
            return $data;
        } elseif ($contents instanceof Stream) {
            return $contents->read(1024 * 1024);
        } else {
            // 处理字符串内容
            return (string)$contents;
        }
    }

    /**
     * 检测内容类型
     * @param mixed $contents
     * @param string $filename
     * @return string|null
     */
    public function detectContentType(mixed $contents, string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
