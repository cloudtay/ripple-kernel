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

use RuntimeException;
use Ripple\Coroutine;
use Ripple\Runtime\Scheduler;
use Throwable;

use function count;

/**
 * WaitGroup
 */
class WaitGroup
{
    /**
     * 等待计数器
     * @var int
     */
    private int $counter = 0;

    /**
     * 等待中的协程
     * @var array<int, Coroutine>
     */
    private array $waitingCoroutines = [];

    /**
     * 增加等待计数
     * @param int $delta 增量
     * @return void
     */
    public function add(int $delta): void
    {
        if ($delta < 0) {
            throw new RuntimeException("WaitGroup counter cannot be negative");
        }

        $this->counter += $delta;
    }

    /**
     * 减少等待计数
     * @return void
     */
    public function done(): void
    {
        if ($this->counter <= 0) {
            throw new RuntimeException("WaitGroup counter underflow");
        }

        $this->counter--;

        // 计数器归零, 唤醒所有等待的协程
        if ($this->counter === 0) {
            $this->notifyAll();
        }
    }

    /**
     * 等待所有协程完成
     * @return void
     */
    public function wait(): void
    {
        if ($this->counter === 0) {
            return;
        }

        $owner = \Co\current();

        // 将当前协程添加到等待队列
        $this->waitingCoroutines[] = $owner;

        // 挂起当前协程
        try {
            $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);

        }
    }

    /**
     * 唤醒所有等待的协程
     * @return void
     */
    private function notifyAll(): void
    {
        foreach ($this->waitingCoroutines as $coroutine) {
            if ($coroutine->isSuspended()) {
                Scheduler::resume($coroutine);
            }
        }

        $this->waitingCoroutines = [];
    }

    /**
     * 获取当前计数
     * @return int 计数
     */
    public function counter(): int
    {
        return $this->counter;
    }

    /**
     * 获取等待中的协程数量
     * @return int 数量
     */
    public function waitingCount(): int
    {
        return count($this->waitingCoroutines);
    }
}
