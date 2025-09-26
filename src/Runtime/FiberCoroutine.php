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
use Ripple\Runtime\Exception\CoroutineStateException;
use Throwable;
use UnexpectedValueException;
use Fiber;

final class FiberCoroutine extends Coroutine
{
    /**
     * Fiber实例
     * @var Fiber
     */
    protected Fiber $fiber;

    /**
     * 获取协程属性
     * @param string $name 属性名
     * @return mixed 属性值
     */
    public function __get(string $name): mixed
    {
        return match($name) {
            'result' => $this->result,
            default =>  new UnexpectedValueException("Property {$name} does not exist or is not accessible")
        };
    }

    /**
     * @return Fiber
     */
    public function key(): object
    {
        return $this->fiber;
    }

    /**
     * 标记协程为可运行状态
     * @return void
     */
    public function runnable(): void
    {
        $this->fiber = new Fiber($this->callback);
        $this->setState(Coroutine::STATE_RUNNABLE);
    }

    /**
     * 启动协程执行
     * @return mixed 协程返回值
     * @throws Throwable
     */
    public function start(): mixed
    {
        if ($this->state !== Coroutine::STATE_RUNNABLE) {
            return new CoroutineStateException("Coroutine is not ready", $this);
        }

        $this->setState(Coroutine::STATE_RUNNING);

        try {
            $result = $this->fiber->start(...$this->args);
        } catch (Throwable $exception) {
            $this->result = $exception;
            $this->setState(Coroutine::STATE_DEAD);
            throw $exception;
        }

        if ($this->fiber->isTerminated()) {
            $this->result = $this->fiber->getReturn();
            $this->setState(Coroutine::STATE_DEAD);
            return $this->result;
        } elseif ($this->fiber->isSuspended()) {
            $this->setState(Coroutine::STATE_WAITING);
        }

        return $result;
    }

    /**
     * 暂停协程执行
     * @param mixed $value 暂停时传递的值
     * @return mixed 恢复时接收的值
     * @throws Throwable
     */
    public function suspend(mixed $value = null): mixed
    {
        if ($this->state !== Coroutine::STATE_RUNNING) {
            throw new CoroutineStateException("Coroutine::suspend() called with coroutine not in running state", $this);
        }

        $this->setState(Coroutine::STATE_WAITING);

        try {
            $result = $this->fiber->suspend($value);
        } catch (Throwable $exception) {
            $this->setState(Coroutine::STATE_DEAD);
            throw $exception;
        }

        $this->setState(Coroutine::STATE_RUNNING);
        return $result;
    }

    /**
     * 恢复协程执行
     * @param mixed $value 恢复时传递的值
     * @return mixed 协程返回值
     * @throws Throwable
     */
    public function resume(mixed $value = null): mixed
    {
        $this->setState(Coroutine::STATE_RUNNING);

        try {
            $result = $this->fiber->resume($value);
        } catch (Throwable $exception) {
            $this->setState(Coroutine::STATE_DEAD);
            throw $exception;
        }

        if ($this->fiber->isTerminated()) {
            $this->result = $this->fiber->getReturn();
            $this->setState(Coroutine::STATE_DEAD);
            return $this->result;
        } elseif ($this->fiber->isSuspended()) {
            $this->setState(Coroutine::STATE_WAITING);
        }

        return $result;
    }

    /**
     * 向协程抛出异常
     * @param Throwable $exception 要抛出的异常
     * @return mixed 协程返回值
     * @throws Throwable
     */
    public function throw(Throwable $exception): mixed
    {
        $this->setState(Coroutine::STATE_RUNNING);

        try {
            $result = $this->fiber->throw($exception);
        } catch (Throwable $exception) {
            $this->setState(Coroutine::STATE_DEAD);
            throw $exception;
        }

        if ($this->fiber->isTerminated()) {
            $this->result = $this->fiber->getReturn();
            $this->setState(Coroutine::STATE_DEAD);
            return $this->result;
        } elseif ($this->fiber->isSuspended()) {
            $this->setState(Coroutine::STATE_WAITING);
        }

        return $result;
    }

    /**
     * 回收协程
     * @param Closure|null $callback
     * @return void
     */
    public function recycle(?Closure $callback = null): void
    {
        if (!$this->isTerminated()) {
            new CoroutineStateException("Unable to recycle a coroutine in use");
        }

        $this->fiber = new Fiber($callback);
        $this->recycleReset();
    }

    /**
     * 重置协程状态
     * @return void
     */
    private function recycleReset(): void
    {
        // 先清空状态监听队列
        $this->highListeners = [];
        $this->lowListeners = [];

        // 清空清理回调队列
        $this->defers = [];

        // 重置 defer 执行标记
        $this->defersExecuted = false;

        // 重置状态
        $this->state = Coroutine::STATE_CREATED;

        // 清空参数
        $this->args = [];

        // 清空结果
        $this->result = null;

        // 清空调试跟踪记录
        $this->clearTrace();
    }
}
