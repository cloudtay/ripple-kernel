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

namespace Ripple\Net\WebSocket\Server;

use Closure;
use Ripple\Net\Http\Server\Request;
use Ripple\Net\WebSocket\Enum\Opcode;
use Ripple\Net\WebSocket\Frame;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function pack;
use function spl_object_id;
use function substr;
use function Co\go;

class Connection
{
    /**
     * 消息处理器
     * @var Closure|null
     */
    public ?Closure $onMessage = null;

    /**
     * 连接处理器
     * @var Closure|null
     */
    public ?Closure $onConnect = null;

    /**
     * 关闭处理器
     * @var Closure|null
     */
    public ?Closure $onClose = null;

    /**
     * 是否已握手
     * @var bool
     */
    private bool $handshake = false;

    /**
     * 是否已关闭
     * @var bool
     */
    private bool $closed = false;

    /**
     * 数据缓冲区
     * @var string
     */
    private string $buffer = '';

    /**
     * 构造函数
     * @param Stream $stream HTTP 连接
     * @param Request $request 原始 HTTP 请求
     */
    public function __construct(public readonly Stream $stream, public readonly Request $request)
    {
        $this->handshake = true;

        // 接管读写事件
        $this->stream->unwatchRead();
        $this->stream->unwatchWrite();

        $this->start();

        // 触发连接事件
        if (isset($this->onConnect)) {
            $handler = $this->onConnect;
            go(fn () => $handler($this));
        }
    }

    /**
     * @return void
     */
    private function start(): void
    {
        try {
            $this->stream->watchRead(function () {
                try {
                    $data = $this->stream->read(8192);
                    if ($data === '' && $this->stream->eof()) {
                        $this->disconnect();
                        return;
                    }

                    $this->processWebSocketData($data);
                } catch (ConnectionException) {
                    $this->disconnect();
                } catch (Throwable $e) {
                    $this->handleError($e);
                }
            });
        } catch (ConnectionException) {
            $this->disconnect();
        }
    }

    /**
     * 处理数据
     * @param string $data
     * @return void
     */
    private function processWebSocketData(string $data): void
    {
        $this->buffer .= $data;
        $this->processFrames();
    }


    /**
     * 处理帧
     * @return void
     */
    private function processFrames(): void
    {
        [$frames, $consumed] = Frame::parseWithConsumed($this->buffer);

        foreach ($frames as $frame) {
            $this->handleFrame($frame);
        }

        if ($consumed > 0) {
            $this->buffer = substr($this->buffer, $consumed);
        }
    }

    /**
     * 处理帧
     * @param Frame $frame
     * @return void
     */
    private function handleFrame(Frame $frame): void
    {
        switch ($frame->getOpcode()) {
            case Opcode::TEXT:
            case Opcode::BINARY:
                if (isset($this->onMessage)) {
                    $handler = $this->onMessage;
                    $payload = $frame->getPayload();
                    go(fn () => $handler($payload, $this));
                }
                break;

            case Opcode::PING:
                $this->sendPong($frame->getPayload());
                break;

            case Opcode::PONG:
                // 处理pong 帧
                break;

            case Opcode::CLOSE:
                $this->disconnect();
                break;
        }
    }

    /**
     * 发送消息
     * @param string $message
     * @param Opcode $opcode
     * @return bool
     */
    public function send(string $message, Opcode $opcode = Opcode::TEXT): bool
    {
        if ($this->closed || !$this->handshake) {
            return false;
        }

        try {
            $frame = new Frame($opcode, $message);
            $this->stream->write($frame->toBytes());
            return true;
        } catch (Throwable $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * 发送文本消息
     * @param string $message
     * @return bool
     */
    public function sendText(string $message): bool
    {
        return $this->send($message, Opcode::TEXT);
    }

    /**
     * 发送二进制消息
     * @param string $message
     * @return bool
     */
    public function sendBinary(string $message): bool
    {
        return $this->send($message, Opcode::BINARY);
    }

    /**
     * 发送 ping 帧
     * @param string $payload
     * @return bool
     */
    public function sendPing(string $payload = ''): bool
    {
        return $this->send($payload, Opcode::PING);
    }

    /**
     * 发送 pong 帧
     * @param string $payload
     * @return bool
     */
    public function sendPong(string $payload = ''): bool
    {
        return $this->send($payload, Opcode::PONG);
    }

    /**
     * 关闭连接
     * @param int $code
     * @param string $reason
     * @return void
     */
    public function disconnect(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $payload = pack('n', $code) . $reason;
            $frame = new Frame(Opcode::CLOSE, $payload);
            $this->stream->write($frame->toBytes());
        } catch (Throwable) {
        }

        $this->stream->close();
        if (isset($this->onClose)) {
            $handler = $this->onClose;
            go(fn () => $handler($this));
        }
    }

    /**
     * @param int $code
     * @param string $reason
     * @return void
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->disconnect($code, $reason);
    }

    /**
     * 错误处理器
     * @var Closure|null
     */
    public ?Closure $onError = null;

    /**
     * 处理错误
     * @param Throwable $error
     * @return void
     */
    private function handleError(Throwable $error): void
    {
        if (isset($this->onError)) {
            $handler = $this->onError;
            go(fn () => $handler($this, $error));
        }
    }

    /**
     * 检查连接是否活跃
     * @return bool
     */
    public function isAlive(): bool
    {
        return !$this->closed && !$this->stream->isClosed();
    }

    /**
     * 检查是否已握手
     * @return bool
     */
    public function isHandshake(): bool
    {
        return $this->handshake;
    }

    /**
     * 获取连接 ID
     * @return int
     */
    public function getId(): int
    {
        return spl_object_id($this);
    }

    /**
     * 获取远程地址
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return 'websocket-connection';
    }
}
