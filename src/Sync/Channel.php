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

namespace Ripple\Sync;

use Ripple\Coroutine;
use Ripple\Runtime\Scheduler;
use SplQueue;
use Throwable;
use RuntimeException;

use function array_shift;
use function count;

/**
 * Channel
 */
class Channel
{
    /**
     * 通道缓冲区
     * @var SplQueue<mixed>
     */
    private SplQueue $buffer;

    /**
     * 缓冲区容量, 0 表示无缓冲通道
     * @var int
     */
    private int $capacity;

    /**
     * 通道是否已关闭
     * @var bool
     */
    private bool $closed = false;

    /**
     * 等待发送的协程队列
     * @var array<int, array{coroutine: Coroutine, value: mixed}>
     */
    private array $sendWaiting = [];

    /**
     * 等待接收的协程队列
     * @var array<int, Coroutine>
     */
    private array $receiveWaiting = [];


    /**
     * 构造函数
     * @param int $capacity 缓冲区容量, 0表示无缓冲通道
     */
    public function __construct(int $capacity = 0)
    {
        $this->capacity = $capacity;
        $this->buffer = new SplQueue();
    }

    /**
     * 发送数据到通道
     * @param mixed $value 要发送的数据
     * @return void
     */
    public function send(mixed $value): void
    {
        $owner = \Co\current();

        // 检查通道是否已关闭
        if ($this->closed) {
            throw new RuntimeException("Channel is closed");
        }

        // 有等待接收的协程, 直接传递数据
        if (!empty($this->receiveWaiting)) {
            $receiver = array_shift($this->receiveWaiting);
            if ($receiver->isSuspended()) {
                Scheduler::resume($receiver, $value);
            }
            return;
        }

        // 缓冲区未满, 将数据放入缓冲区
        if ($this->buffer->count() < $this->capacity) {
            $this->buffer->enqueue($value);
            return;
        }

        // 缓冲区已满, 等待接收者
        $this->sendWaiting[] = [
            'coroutine' => $owner,
            'value' => $value
        ];

        try {
            $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 尝试发送数据到通道
     * 如果无法立即发送, 返回false
     * @param mixed $value 要发送的数据
     * @return bool true表示发送成功, false表示无法发送
     */
    public function trySend(mixed $value): bool
    {
        // 检查通道是否已关闭
        if ($this->closed) {
            return false;
        }

        // 有等待接收的协程, 直接传递数据
        if (!empty($this->receiveWaiting)) {
            $receiver = array_shift($this->receiveWaiting);
            if ($receiver->isSuspended()) {
                Scheduler::resume($receiver, $value);
            }
            return true;
        }

        // 缓冲区未满, 将数据放入缓冲区
        if ($this->buffer->count() < $this->capacity) {
            $this->buffer->enqueue($value);
            return true;
        }

        // 缓冲区已满, 无法发送
        return false;
    }

    /**
     * 从通道接收数据
     * @return mixed 接收到的数据
     */
    public function receive(): mixed
    {
        $owner = \Co\current();

        // 通道已关闭且缓冲区为空返回null
        if ($this->closed && $this->buffer->isEmpty()) {
            return null;
        }

        // 缓冲区有数据直接返回
        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();

            // 有等待发送的协程将数据放入缓冲区
            if (!empty($this->sendWaiting)) {
                $sender = array_shift($this->sendWaiting);
                $this->buffer->enqueue($sender['value']);
                if ($sender['coroutine']->isSuspended()) {
                    Scheduler::resume($sender['coroutine']);
                }
            }

            return $value;
        }

        // 有等待发送的协程, 直接接收数据
        if (!empty($this->sendWaiting)) {
            $sender = array_shift($this->sendWaiting);
            if ($sender['coroutine']->isSuspended()) {
                Scheduler::resume($sender['coroutine']);
            }
            return $sender['value'];
        }

        // 没有数据可接收, 等待发送者
        $this->receiveWaiting[] = $owner;
        try {
            return $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 尝试从通道接收数据, 通过引用接受结果
     * 如果无法立即接收, 返回false
     * @param mixed &$value 接收到的数据
     * @return bool true表示接收成功, false表示无法接收
     */
    public function tryReceive(mixed &$value): bool
    {
        // 通道已关闭且缓冲区为空, 返回false
        if ($this->closed && $this->buffer->isEmpty()) {
            $value = null;
            return false;
        }

        // 缓冲区有数据, 直接返回
        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();

            // 有等待发送的协程, 将数据放入缓冲区
            if (!empty($this->sendWaiting)) {
                $sender = array_shift($this->sendWaiting);
                $this->buffer->enqueue($sender['value']);
                if ($sender['coroutine']->isSuspended()) {
                    Scheduler::resume($sender['coroutine']);
                }
            }

            return true;
        }

        // 有等待发送的协程, 直接接收数据
        if (!empty($this->sendWaiting)) {
            $sender = array_shift($this->sendWaiting);
            $value = $sender['value'];
            if ($sender['coroutine']->isSuspended()) {
                Scheduler::resume($sender['coroutine']);
            }
            return true;
        }

        // 没有数据可接收
        $value = null;
        return false;
    }

    /**
     * 关闭通道
     * 关闭后不能再发送数据, 但可以接收剩余的数据
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;

        // 唤醒所有等待接收的协程
        $receivers = $this->receiveWaiting;
        $this->receiveWaiting = [];

        // 唤醒所有等待发送的协程, 抛出异常
        $senders = $this->sendWaiting;
        $this->sendWaiting = [];

        // 唤醒协程
        foreach ($receivers as $receiver) {
            if ($receiver->isSuspended()) {
                Scheduler::resume($receiver);
            }
        }

        foreach ($senders as $sender) {
            if ($sender['coroutine']->isSuspended()) {
                Scheduler::throw($sender['coroutine'], new RuntimeException("Channel is closed"));
            }
        }
    }

    /**
     * 检查通道是否已关闭
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * 获取通道容量
     * @return int
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * 获取当前缓冲区中的数据数量
     * @return int
     */
    public function bufferSize(): int
    {
        return $this->buffer->count();
    }

    /**
     * 获取等待发送的协程数量
     * @return int
     */
    public function sendWaitingCount(): int
    {
        return count($this->sendWaiting);
    }

    /**
     * 获取等待接收的协程数量
     * @return int
     */
    public function receiveWaitingCount(): int
    {
        return count($this->receiveWaiting);
    }
}
