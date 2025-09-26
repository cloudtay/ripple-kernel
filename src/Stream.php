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

namespace Ripple;

use Ripple\Runtime\Exception\CoroutineStateException;
use Ripple\Runtime\Scheduler;
use Ripple\Stream\RingBuffer;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\BaseStream;
use Throwable;
use Closure;

use function sprintf;
use function strlen;
use function substr;
use function min;
use function stream_socket_shutdown;
use function max;
use function is_array;
use function stream_context_create;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_server;
use function str_replace;

use const STREAM_SHUT_RD;
use const STREAM_SHUT_WR;
use const STREAM_SHUT_RDWR;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Stream extends BaseStream
{
    /**
     * 写缓冲区
     * @var RingBuffer
     */
    protected RingBuffer $writeBuf;

    /**
     * 连接是否已断开
     * @var bool
     */
    protected bool $down = false;

    /**
     * 是否已关闭
     * @var bool
     */
    protected bool $closed = false;

    /**
     * 读半关闭标记
     * @var bool
     */
    protected bool $rdClosed = false;

    /**
     * 写半关闭标记
     * @var bool
     */
    protected bool $wrClosed = false;

    /**
     * 最大缓冲区大小 (bytes)
     * @var int
     */
    protected int $writeBufferMax = 1024 * 1024; // 1MB

    /**
     * 写入单次最大块大小 (bytes)
     * @var int
     */
    protected int $chunkSize = 61440; // 60KB

    /**
     * @param mixed $resource 底层资源
     * @param array{
     *   write_buffer_size?: int,         // 写缓冲区初始容量, 默认32kb
     *   write_buffer_max?: int,          // 写缓冲区上限, 默认1MB
     *   write_chunk_size?: int           // 单次最大写入块大小, 默认 60KB
     * } $options 运行时配置项
     */
    public function __construct(mixed $resource, array $options = [])
    {
        parent::__construct($resource);

        // 默认值填充
        $options += [
            'write_buffer_size' => 32768,
            'write_buffer_max' => 1024 * 1024,
            'write_chunk_size' => 61440,
        ];

        // 初始化写缓冲区
        $this->writeBuf = new RingBuffer((int)$options['write_buffer_size']);

        // 可配置最大写缓冲区尺寸
        $this->writeBufferMax = (int)$options['write_buffer_max'];

        // 写入单次最大块大小
        $this->chunkSize = max(1, (int)$options['write_chunk_size']);
    }

    /**
     * 析构函数, 确保清理所有监听器
     */
    public function __destruct()
    {
        $this->unwatchAll();
    }

    /**
     * @param string $string 要写入的数据
     * @return int 写入的字节数, 总是等于输入数据长度, 否则抛出异常
     * @throws ConnectionException
     */
    public function writeAll(string $string, ?float $timeout = null): int
    {
        // 检查连接状态
        if ($this->closed || $this->down || $this->wrClosed) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        $totalLength = strlen($string);
        if ($totalLength === 0) {
            return 0;
        }

        try {
            // 先尝试直接写入
            $directWritten = parent::write($string);
            if ($directWritten === $totalLength) {
                return $totalLength;
            }

            // 部分写入, 将剩余数据放入缓冲区
            $remaining = substr($string, $directWritten);
            $remainingLength = strlen($remaining);
            $buffered = $this->writeBuf->write($remaining);

            // 检查缓冲区容量限制
            $currentBufferSize = $this->writeBuf->length();
            if ($currentBufferSize > $this->writeBufferMax) {
                throw new ConnectionException(
                    sprintf(
                        "Write buffer overflow: current=%s, requested=%s, max=%s",
                        $currentBufferSize,
                        $totalLength,
                        $this->writeBufferMax
                    )
                );
            }

            if ($buffered !== $remainingLength) {
                throw new ConnectionException('Failed to buffer remaining data completely');
            }

            $this->flush($timeout);
            return $totalLength;
        } catch (Throwable $exception) {
            throw new ConnectionException(
                sprintf("连接关闭: %s", $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * 读取
     * @param int $length
     * @return string
     * @throws ConnectionException
     */
    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if ($this->closed || $this->down || $this->rdClosed) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        try {
            return parent::read($length);
        } catch (Throwable $exception) {
            // 读失败由上层决定断开时机
            throw new ConnectionException(
                sprintf('读取失败: %s', $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * 挂起中的协程
     * @var Coroutine|null
     */
    protected ?Coroutine $wco = null;

    /**
     * 等待缓冲区完全刷新
     * @param float|int|null $timeout
     * @return void
     * @throws ConnectionException|Throwable
     */
    public function flush(float|int|null $timeout = null): void
    {
        $this->wco = \Co\current();

        // 缓冲区空
        if ($this->writeBuf->isEmpty()) {
            $this->wco = null;
            return;
        }

        $this->watchWrite(function () {
            try {
                if ($this->closed || $this->down) {
                    throw new ConnectionException('Stream is closed or disconnected');
                }

                $this->flushOnce();
                if ($this->writeBuf->isEmpty()) {
                    Scheduler::resume($this->wco)->resolve(CoroutineStateException::class);
                }
            } catch (Throwable $exception) {
                Scheduler::throw($this->wco, $exception)->resolve(CoroutineStateException::class);
            }
        });

        $timer = null;
        if ($timeout && $timeout >= 0) {
            $timer = Time::afterFunc($timeout, function () use ($timeout) {
                $this->wco->isSuspended() && Scheduler::throw($this->wco, new ConnectionException('Write timeout'));
            });
        }

        try {
            $this->wco->suspend();
        } finally {
            $timer?->stop();
            $this->unwatchWrite();
            $this->wco = null;
        }
    }

    /**
     * 写半关闭
     * @return void
     */
    public function shutdownWrite(): void
    {
        if ($this->wrClosed) {
            return;
        }

        try {
            $this->flush();
        } catch (Throwable) {
            // 尝试清空缓冲区, 清空不了也没事直接吃掉异常
        }

        // 清理写监听器
        $this->unwatchWrite();
        stream_socket_shutdown($this->resource, STREAM_SHUT_WR);
        $this->wrClosed = true;
    }

    /**
     * 读半关闭
     * @return void
     */
    public function shutdownRead(): void
    {
        if ($this->rdClosed) {
            return;
        }

        // 清理读监听器
        $this->unwatchRead();

        stream_socket_shutdown($this->resource, STREAM_SHUT_RD);
        $this->rdClosed = true;
    }

    /**
     * 通用半关闭接口
     * @param int $how STREAM_SHUT_RD | STREAM_SHUT_WR | STREAM_SHUT_RDWR
     * @return void
     */
    public function shutdown(int $how): void
    {
        switch ($how) {
            case STREAM_SHUT_RD:
                $this->shutdownRead();
                break;
            case STREAM_SHUT_WR:
                $this->shutdownWrite();
                break;
            case STREAM_SHUT_RDWR:
                $this->shutdownWrite();
                $this->shutdownRead();
                break;
        }
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        // 清理所有监听器
        $this->unwatchAll();
        parent::close();
        $this->closed = true;

        if ($this->wco) {
            Scheduler::throw($this->wco, new ConnectionException('Stream is closed or disconnected'))
                ->resolve(CoroutineStateException::class);
        }
    }

    /**
     * 启用SSL
     * @param int|float $timeout
     * @param int|null $cryptoMethod
     * @return Stream
     * @throws ConnectionException
     */
    public function enableSSL(
        int|null  $cryptoMethod = null,
        int|float $timeout = 0,
    ): Stream {
        if (!$cryptoMethod) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }

        $handshakeResult = stream_socket_enable_crypto($this->resource, true, $cryptoMethod);
        if ($handshakeResult === false) {
            $this->close();
            throw new ConnectionException('Failed to enable crypto.');
        }

        if ($handshakeResult === true) {
            return $this;
        }

        $owner = \Co\current();
        if ($handshakeResult === 0) {
            $this->watchRead(function () use ($owner) {
                try {
                    $handshakeResult = stream_socket_enable_crypto(
                        $this->resource,
                        true,
                        STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
                    );

                    if ($handshakeResult === false) {
                        throw new ConnectionException('Failed to enable crypto.');
                    }

                    if ($handshakeResult === true) {
                        $this->unwatchRead();
                        Scheduler::resume($owner);
                    }
                } catch (Throwable $exception) {
                    $this->close();
                    Scheduler::throw($owner, $exception);
                }
            });
        }

        try {
            $owner->suspend();
        } catch (Throwable $e) {
            throw new ConnectionException('Failed to enable crypto: ' . $e->getMessage());
        } finally {
            $this->unwatchRead();
        }
        return $this;
    }

    /**
     * 获取写缓冲区状态信息
     * @return RingBuffer
     */
    public function writeBuffer(): RingBuffer
    {
        return $this->writeBuf;
    }

    /**
     * 获取最大写缓冲区大小 (bytes)
     * @return int
     */
    public function writeBufferMax(): int
    {
        return $this->writeBufferMax;
    }

    /**
     * 设置最大写缓冲区大小 (bytes)
     * @param int $bytes
     * @return void
     */
    public function setWriteBufferMax(int $bytes): void
    {
        $this->writeBufferMax = max(0, $bytes);
    }

    /**
     * 设置单次最大写入块大小 (bytes)
     * @param int $bytes
     * @return void
     */
    public function setChunkSize(int $bytes): void
    {
        $this->chunkSize = max(1, $bytes);
    }

    /**
     * 非阻塞写入,尝试直接写入底层,未写完的部分写入缓冲区, 配合 flushWriteOnce() 自行控制刷新缓冲区时机
     * @param string $string
     * @return int 接受的字节数, 等于输入字节数
     * @throws ConnectionException
     */
    public function writeAsync(string $string): int
    {
        if ($this->closed || $this->down || $this->wrClosed) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        $totalLength = strlen($string);
        if ($totalLength === 0) {
            return 0;
        }

        if ($this->writeBuf->length() > 0) {
            $this->writeBuf->write($string);
            return $totalLength;
        }

        // 先尝试直接写入
        $directWritten = parent::write($string);
        if ($directWritten === $totalLength) {
            return $totalLength;
        }

        // 将剩余数据写入缓冲区
        $remaining = substr($string, $directWritten);
        $remainingLength = strlen($remaining);
        $buffered = $this->writeBuf->write($remaining);

        // 检查缓冲区容量限制
        $currentBufferSize = $this->writeBuf->length();
        if ($currentBufferSize > $this->writeBufferMax) {
            throw new ConnectionException(
                sprintf(
                    'Write buffer overflow: current=%s, requested=%s, max=%s',
                    $currentBufferSize,
                    $totalLength,
                    $this->writeBufferMax
                )
            );
        }

        if ($buffered !== $remainingLength) {
            throw new ConnectionException('Failed to buffer remaining data completely');
        }

        return $totalLength;
    }

    /**
     * 一次性刷新缓冲区
     * @return void
     * @throws ConnectionException
     */
    public function flushOnce(): void
    {
        if ($this->closed || $this->down) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        $maxChunk = $this->chunkSize;
        while (!$this->writeBuf->isEmpty()) {
            $available = $this->writeBuf->length();
            $chunkSize = min($available, $maxChunk);
            $chunk = $this->writeBuf->peek($chunkSize);
            if ($chunk === '') {
                break;
            }

            $written = parent::write($chunk);
            if ($written === 0) {
                break; // 暂不可写
            }

            $this->writeBuf->read($written);
            if ($written < $chunkSize) {
                break; // 本次未写满
            }
        }
    }

    /**
     * 查询是否已关闭
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * 读监听器ID
     * @var int|null
     */
    protected ?int $readWatchId = null;

    /**
     * 写监听器ID
     * @var int|null
     */
    protected ?int $writeWatchId = null;

    /**
     * 是否正在监听读事件
     * @var bool
     */
    protected bool $isWatchingRead = false;

    /**
     * 是否正在监听写事件
     * @var bool
     */
    protected bool $isWatchingWrite = false;

    /**
     * 监听读事件
     * @param Closure $callback 读事件回调函数
     * @return int 监听器ID
     * @throws ConnectionException
     */
    public function watchRead(Closure $callback): int
    {
        if ($this->closed || $this->down || $this->rdClosed) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        if ($this->isWatchingRead) {
            throw new ConnectionException('Already watching read events');
        }

        $this->readWatchId = Event::watchRead($this->resource, $callback);
        $this->isWatchingRead = true;

        return $this->readWatchId;
    }

    /**
     * 监听写事件
     * @param Closure $callback 写事件回调函数
     * @return int 监听器ID
     * @throws ConnectionException
     */
    public function watchWrite(Closure $callback): int
    {
        if ($this->closed || $this->down || $this->wrClosed) {
            throw new ConnectionException('Stream is closed or disconnected');
        }

        if ($this->isWatchingWrite) {
            throw new ConnectionException('Already watching write events');
        }

        $this->writeWatchId = Event::watchWrite($this->resource, $callback);
        $this->isWatchingWrite = true;

        return $this->writeWatchId;
    }

    /**
     * 取消读事件监听
     * @return void
     */
    public function unwatchRead(): void
    {
        if ($this->readWatchId !== null) {
            Event::unwatch($this->readWatchId);
            $this->readWatchId = null;
            $this->isWatchingRead = false;
        }
    }

    /**
     * 取消写事件监听
     * @return void
     */
    public function unwatchWrite(): void
    {
        if ($this->writeWatchId !== null) {
            Event::unwatch($this->writeWatchId);
            $this->writeWatchId = null;
            $this->isWatchingWrite = false;
        }
    }

    /**
     * 取消所有事件监听
     * @return void
     */
    public function unwatchAll(): void
    {
        $this->unwatchRead();
        $this->unwatchWrite();
    }

    /**
     * 查询是否正在监听读事件
     * @return bool
     */
    public function isWatchingRead(): bool
    {
        return $this->isWatchingRead;
    }

    /**
     * 查询是否正在监听写事件
     * @return bool
     */
    public function isWatchingWrite(): bool
    {
        return $this->isWatchingWrite;
    }

    /**
     * 获取读监听器ID
     * @return int|null
     */
    public function readWatchId(): ?int
    {
        return $this->readWatchId;
    }

    /**
     * 获取写监听器ID
     * @return int|null
     */
    public function writeWatchId(): ?int
    {
        return $this->writeWatchId;
    }

    /**
     * @param string     $address
     * @param int|float $timeout
     * @param mixed|null $context
     * @return Stream
     * @throws ConnectionException
     */
    public static function connect(string $address, int|float $timeout = 0, mixed $context = null): Stream
    {
        $address = str_replace('ssl://', 'tcp://', $address);
        $connection = stream_socket_client(
            $address,
            $_,
            $_,
            $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );

        if (!$connection) {
            throw new ConnectionException('Failed to connect to the server.');
        }

        $stream = new static($connection);

        try {
            $owner = \Co\current();
            $stream->watchWrite(static fn () => Scheduler::resume($owner));
            $owner->suspend();
            return $stream;
        } catch (Throwable $e) {
            $stream->close();
            throw new ConnectionException($e->getMessage());
        } finally {
            $stream->unwatchWrite();
        }
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     * @return static|false
     */
    public static function server(string $address, mixed $context = null): Stream|false
    {
        if (is_array($context)) {
            $context = stream_context_create($context);
        }

        $server = stream_socket_server(
            $address,
            $_errCode,
            $_errMsg,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        return $server ? new static($server) : false;
    }
}
