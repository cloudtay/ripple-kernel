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

namespace Ripple\Runtime;

use Closure;
use Ripple\Coroutine;
use Ripple\Watch\Interface\WatchAbstract;
use Throwable;

use function call_user_func;

/**
 * 将Main上下文伪装成协程序空间
 * @property-read WatchAbstract $watcher
 */
final class MainCoroutine extends Coroutine
{
    /**
     * 主上下文错误异常
     * @var ?Throwable
     */
    private ?Throwable $hasExcept = null;

    /**
     * @var true
     */
    private bool $hasMessage = false;

    /**
     * @var mixed
     */
    private mixed $suspendValue = null;

    /**
     * 设置主协程为可运行状态
     * @return void
     */
    public function runnable(): void
    {
        $this->setState(Coroutine::STATE_RUNNABLE);
    }

    /**
     * 启动主协程
     * @return bool 启动是否成功
     */
    public function start(): bool
    {
        $this->setState(Coroutine::STATE_WAITING);
        call_user_func($this->callback);
        return true;
    }

    /**
     * 挂起主协程并返回传入值
     * @param ?mixed $value 挂起时传入的值
     * @return mixed 恢复时的返回值
     * @throws Throwable
     */
    public function suspend(mixed $value = null): mixed
    {
        $this->suspendValue = $value;
        try {
            if ($this->state === Coroutine::STATE_CREATED) {
                Scheduler::start($this);
            } else {
                $this->setState(Coroutine::STATE_WAITING);
                Scheduler::tick();
            }

            // 结果暂存
            $result = $this->result;

            // 置空结果
            $this->result = null;
            $this->suspendValue = null;
            return $result;
        } finally {
            $this->setState(Coroutine::STATE_RUNNING);
        }
    }

    /**
     * 恢复主协程执行
     * @param ?mixed $value 恢复时传入的值
     * @return bool 恢复是否成功
     * @throws Throwable
     */
    public function resume(mixed $value = null): mixed
    {
        $this->hasMessage = true;
        $this->result = $value;

        $owner = \Co\current();
        if ($owner instanceof FiberCoroutine) {
            Scheduler::nextTick(fn () => Scheduler::resume($owner, $this->suspendValue));
            return $owner->suspend();
        }

        return true;
    }

    /**
     * 向主协程抛出异常
     * @param Throwable $exception 要抛出的异常对象
     * @return bool 异常设置是否成功
     * @throws Throwable
     */
    public function throw(Throwable $exception): mixed
    {
        $this->hasMessage = true;
        $this->hasExcept = $exception;

        $owner = \Co\current();
        if ($owner instanceof FiberCoroutine) {
            Scheduler::nextTick(fn () => Scheduler::resume($owner, $this->suspendValue));
            return $owner->suspend();
        }

        return true;
    }

    /**
     * 回收主协程资源
     * @param Closure|null $callback
     * @return void
     */
    public function recycle(?Closure $callback = null): void
    {
        $this->setState(Coroutine::STATE_RUNNABLE);
    }

    /**
     * 获取主协程的唯一标识
     * @return object 主协程实例
     */
    public function key(): object
    {
        return $this;
    }

    /**
     * @return bool
     * @throws Throwable
     */
    public function shiftMessage(): bool
    {
        if ($this->hasMessage) {
            $err = $this->hasExcept;

            $this->hasMessage = false;
            $this->hasExcept = null;

            if ($err) {
                throw $err;
            }
            return true;
        }

        return false;
    }
}
