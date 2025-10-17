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
use Ripple\Runtime\Scheduler;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function ctype_digit;
use function explode;
use function hexdec;
use function is_array;
use function is_string;
use function preg_match;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

trait ClientResponse
{
    /**
     * @var array|null 解析状态
     */
    private ?array $parseState = null;

    /**
     * @var Coroutine|null 当前协程
     */
    private ?Coroutine $owner = null;

    /**
     * @param Stream $stream
     * @throws ConnectionException
     * @return void
     */
    public function fill(Stream $stream): void
    {
        $this->owner = \Co\current();
        $this->initParseState();

        $stream->watchRead(function () use ($stream) {
            try {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    return;
                }
                $this->parseState['buffer'] .= $chunk;

                // 解析状态行
                if (!$this->parseState['parsedStatus']) {
                    if ($this->parseStatusLine()) {
                        $this->parseState['parsedStatus'] = true;
                    } else {
                        return;
                    }
                }

                // 解析响应头
                if ($this->parseState['parsedStatus'] && !$this->parseState['parsedHeaders']) {
                    if ($this->parseHeaders()) {
                        $this->parseState['parsedHeaders'] = true;
                        $this->determineBodyType();
                    } else {
                        return;
                    }
                }

                // 解析响应体
                if ($this->parseState['parsedHeaders']) {
                    if ($this->parseBody()) {
                        return;
                    }
                }
            } catch (Throwable $e) {
                $this->handleParseError($e);
            }
        });

        try {
            $this->owner->suspend();
        } finally {
            $this->finalize();
            $stream->unwatchRead();
            $this->parseState = null;
            $this->owner = null;
        }
    }

    /**
     * 初始化解析状态
     * @return void
     */
    private function initParseState(): void
    {
        $this->parseState = [
            'buffer' => '',
            'parsedStatus' => false,
            'parsedHeaders' => false,
            'expectedLength' => null,
            'isChunked' => false,
            'body' => ''
        ];
    }

    /**
     * @return bool
     * @throws ConnectionException
     */
    private function parseStatusLine(): bool
    {
        $pos = strpos($this->parseState['buffer'], "\r\n");
        if ($pos === false) {
            return false;
        }

        $statusLine = substr($this->parseState['buffer'], 0, $pos);
        $this->parseState['buffer'] = substr($this->parseState['buffer'], $pos + 2);

        if (preg_match('#^HTTP/\d+\.\d+\s+(\d{3})\s*(.*)$#', $statusLine, $m)) {
            $code = (int)$m[1];
            $text = $m[2] !== '' ? $m[2] : '';
            $this->setStatusCode($code);
            if ($text !== '') {
                $this->setStatusText($text);
            }
            return true;
        }

        throw new ConnectionException('Invalid HTTP status line');
    }

    /**
     * @return bool
     */
    private function parseHeaders(): bool
    {
        $headersEnd = strpos($this->parseState['buffer'], "\r\n\r\n");
        if ($headersEnd === false) {
            return false;
        }

        $headersRaw = substr($this->parseState['buffer'], 0, $headersEnd);
        $this->parseState['buffer'] = substr($this->parseState['buffer'], $headersEnd + 4);

        $this->processHeaderLines($headersRaw);
        return true;
    }

    /**
     * @param string $headersRaw
     * @return void
     */
    private function processHeaderLines(string $headersRaw): void
    {
        $lines = explode("\r\n", $headersRaw);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }

            $name = trim(substr($line, 0, $p));
            $value = trim(substr($line, $p + 1));

            $this->addHeader($name, $value);
            $this->processCookie($name, $value);
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private function addHeader(string $name, string $value): void
    {
        if (!isset($this->headers[$name])) {
            $this->headers[$name] = $value;
        } else {
            if (is_array($this->headers[$name])) {
                $this->headers[$name][] = $value;
            } else {
                $this->headers[$name] = [$this->headers[$name], $value];
            }
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private function processCookie(string $name, string $value): void
    {
        if (strtolower($name) === 'set-cookie') {
            $cookieLine = $value;
            $eq = strpos($cookieLine, '=');
            $cookieName = $eq !== false ? substr($cookieLine, 0, $eq) : $cookieLine;
            $cookieName = trim($cookieName);

            if ($cookieName !== '') {
                $this->cookieLines[$cookieName] = $cookieLine;
            } else {
                $this->cookieLines[] = $cookieLine;
            }
        }
    }

    /**
     * 确定响应体类型
     * @return void
     */
    private function determineBodyType(): void
    {
        $te = $this->header('Transfer-Encoding');
        $cl = $this->header('Content-Length');

        if (is_string($te) && stripos($te, 'chunked') !== false) {
            $this->parseState['isChunked'] = true;
        } elseif (is_string($cl) && $cl !== '' && ctype_digit($cl)) {
            $this->parseState['expectedLength'] = (int)$cl;
        } else {
            $this->parseState['expectedLength'] = null;
        }
    }

    /**
     * @return bool
     * @throws ConnectionException
     */
    private function parseBody(): bool
    {
        if ($this->parseState['isChunked']) {
            return $this->parseChunkedBody();
        } elseif ($this->parseState['expectedLength'] !== null) {
            return $this->parseFixedLengthBody();
        }

        return false;
    }

    /**
     * @return bool
     * @throws ConnectionException
     */
    private function parseChunkedBody(): bool
    {
        while (true) {
            $lineEnd = strpos($this->parseState['buffer'], "\r\n");
            if ($lineEnd === false) {
                break;
            }

            $lenHex = trim(substr($this->parseState['buffer'], 0, $lineEnd));
            if ($lenHex === '') {
                $this->parseState['buffer'] = substr($this->parseState['buffer'], $lineEnd + 2);
                continue;
            }

            if (!preg_match('/^[0-9a-fA-F]+$/', $lenHex)) {
                throw new ConnectionException('Invalid chunk size');
            }

            $chunkLen = hexdec($lenHex);
            $this->parseState['buffer'] = substr($this->parseState['buffer'], $lineEnd + 2);

            if (strlen($this->parseState['buffer']) < $chunkLen + 2) {
                break;
            }

            $chunkData = substr($this->parseState['buffer'], 0, $chunkLen);
            $this->parseState['body'] .= $chunkData;
            $this->parseState['buffer'] = substr($this->parseState['buffer'], $chunkLen + 2);

            if ($chunkLen === 0) {
                Scheduler::tryResume($this->owner);
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    private function parseFixedLengthBody(): bool
    {
        $missing = $this->parseState['expectedLength'] - strlen($this->parseState['body']) - strlen($this->parseState['buffer']);
        if ($missing <= 0) {
            $needed = $this->parseState['expectedLength'] - strlen($this->parseState['body']);
            $this->parseState['body'] .= substr($this->parseState['buffer'], 0, $needed);
            $this->parseState['buffer'] = substr($this->parseState['buffer'], $needed);
            Scheduler::tryResume($this->owner);
            return true;
        }

        $this->parseState['body'] .= $this->parseState['buffer'];
        $this->parseState['buffer'] = '';
        return false;
    }

    /**
     * @param Throwable $e
     * @return void
     */
    private function handleParseError(Throwable $e): void
    {
        if ($this->parseState['parsedHeaders'] && $this->parseState['expectedLength'] === null && !$this->parseState['isChunked']) {
            if ($this->parseState['buffer'] !== '') {
                $this->parseState['body'] .= $this->parseState['buffer'];
                $this->parseState['buffer'] = '';
            }
            $this->withBody($this->parseState['body']);
            Scheduler::tryResume($this->owner);
            return;
        }

        Scheduler::tryThrow($this->owner, $e);
    }

    /**
     * 完成响应解析
     * @return void
     */
    private function finalize(): void
    {
        if (!$this->parseState['parsedHeaders']) {
            return;
        }

        if (!$this->parseState['isChunked']) {
            if ($this->parseState['body'] !== '') {
                $this->withBody($this->parseState['body']);
            } elseif ($this->parseState['expectedLength'] === 0) {
                $this->withBody('');
            }
        } else {
            if ($this->parseState['body'] !== '') {
                $this->withBody($this->parseState['body']);
            }
        }
    }
}
