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

use function array_shift;
use function count;

/**
 * 互斥锁
 */
class Mutex
{
    /**
     * 锁状态
     * @var bool
     */
    private bool $locked = false;

    /**
     * 等待锁的协程队列
     * @var array<int, Coroutine>
     */
    private array $waitingCoroutines = [];

    /**
     * 当前持有锁的协程
     * @var ?Coroutine
     */
    private ?Coroutine $owner = null;

    /**
     * 加锁
     * 如果被占用, 当前协程挂起等待
     * @return void
     */
    public function lock(): void
    {
        $owner = \Co\current();

        // 当前协程已经持有锁
        if ($this->owner === $owner) {
            return;
        }

        // 锁已被其他协程持有
        if (!$this->locked) {
            $this->locked = true;
            $this->owner = $owner;
            return;
        }

        $this->waitingCoroutines[] = $owner;
        try {
            $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);

        }

        // 获得锁
        $this->locked = true;
        $this->owner = $owner;
    }

    /**
     * 尝试加锁
     * @return bool 获得锁返回true
     */
    public function tryLock(): bool
    {
        $owner = \Co\current();

        // 当前协程已经持有锁
        if ($this->owner === $owner) {
            return true;
        }

        // 锁已被其他协程持有, 返回false
        if ($this->locked) {
            return false;
        }

        // 获得锁
        $this->locked = true;
        $this->owner = $owner;
        return true;
    }

    /**
     * 解锁
     * 仅持有者可解锁
     * @return void
     */
    public function unlock(): void
    {
        $owner = \Co\current();

        // 检查是否是锁的持有者
        if ($this->owner !== $owner) {
            throw new RuntimeException("Mutex::unlock() called by non-owner coroutine");
        }

        // 解锁
        $this->locked = false;
        $this->owner = null;

        // 唤醒下一个等待的协程
        if (!empty($this->waitingCoroutines)) {
            $nextCoroutine = array_shift($this->waitingCoroutines);
            if ($nextCoroutine->isSuspended()) {
                Scheduler::resume($nextCoroutine);
            }
        }
    }

    /**
     * 检查锁是否被锁定
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * 获取当前持有锁的协程
     * @return ?Coroutine
     */
    public function owner(): ?Coroutine
    {
        return $this->owner;
    }

    /**
     * 获取等待锁的协程数量
     * @return int
     */
    public function waitingCount(): int
    {
        return count($this->waitingCoroutines);
    }
}
