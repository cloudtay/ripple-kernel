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

namespace Ripple\Runtime\Trait;

use Closure;
use Ripple\Coroutine;

use function array_pop;
use function array_reverse;
use function array_slice;
use function debug_backtrace;

use const DEBUG_BACKTRACE_PROVIDE_OBJECT;

/**
 * 状态管理 Trait
 */
trait StateMachine
{
    /**
     * 高优先级监听器队列
     * @var array<int, array{state: string, callback: Closure, debugBacktrace?: array}>
     */
    protected array $highListeners = [];

    /**
     * 低优先级监听器队列
     * @var array<int, array{state: string, callback: Closure, debugBacktrace?: array}>
     */
    protected array $lowListeners = [];

    /**
     * @var string
     */
    protected string $state;

    /**
     * 获取当前状态
     * @return string
     */
    public function state(): string
    {
        return $this->state;
    }

    /**
     * 设置状态
     * @param string $state 目标状态
     * @return void
     */
    public function setState(string $state): void
    {
        if (isset($this->state) && $this->state === $state) {
            return;
        }

        //        if ($state === Coroutine::STATE_DEAD && isset($this->fiber)) {
        //            if (!$this->fiber->isTerminated()) {
        //                throw new CoroutineException('状态不同步');
        //            }
        //        }

        $this->state = $state;

        // 内部分发
        $this->dispatchStateChange($this->state);
    }

    /**
     * 监听状态,只触发一次
     * @param string $state 要监听的状态
     * @param Closure $callback 回调函数
     * @param bool $prepare 是否使用高优先级队列
     * @return void
     */
    public function onState(string $state, Closure $callback, bool $prepare = false): void
    {
        $item = [
            'state' => $state,
            'callback' => $callback,
            'debugBacktrace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 20), 1)
        ];

        if ($prepare) {
            $this->highListeners[] = $item;
        } else {
            $this->lowListeners[] = $item;
        }
    }


    /**
     * 是否为指定状态
     * @param string $state 要检查的状态
     * @return bool
     */
    public function isState(string $state): bool
    {
        return $this->state === $state;
    }

    /**
     * 是否可运行
     * @return bool
     */
    public function isRunnable(): bool
    {
        return $this->state === Coroutine::STATE_RUNNABLE;
    }

    /**
     * 是否已创建
     * @return bool
     */
    public function isCreated(): bool
    {
        return $this->state === Coroutine::STATE_CREATED;
    }


    /**
     * 检查协程是否正在运行
     * 判断协程当前是否处于运行状态
     * @return bool 如果协程正在运行则返回true, 否则返回false
     */
    public function isRunning(): bool
    {
        return $this->state === Coroutine::STATE_RUNNING;
    }

    /**
     * 检查协程是否已暂停
     * 判断协程当前是否处于等待状态
     * @return bool 如果协程已暂停则返回true, 否则返回false
     */
    public function isSuspended(): bool
    {
        return $this->state === Coroutine::STATE_WAITING;
    }

    /**
     * 检查协程是否已终止
     * 判断协程当前是否处于终止状态
     * @return bool 如果协程已终止则返回true, 否则返回false
     */
    public function isTerminated(): bool
    {
        return $this->state === Coroutine::STATE_DEAD;
    }

    /**
     * 获取延迟执行队列
     * 返回协程终止时需要执行的回调函数队列
     * @return Closure[] 延迟执行的回调函数数组
     */
    public function defers(): array
    {
        return $this->defers;
    }

    /**
     * 添加延迟执行回调
     * 将回调函数添加到延迟执行队列中, 协程终止时执行
     * @param Closure $callback 要延迟执行的回调函数
     * @return void
     */
    public function defer(Closure $callback): void
    {
        $this->defers[] = $callback;
    }

    /**
     * 内部分发状态变更
     * @param string $state 当前状态
     * @return void
     */
    protected function dispatchStateChange(string $state): void
    {
        $this->processQueue($this->highListeners, $state);
        $this->processQueue($this->lowListeners, $state);
    }

    /**
     * 处理队列,先进后出
     * @param array $queue 队列引用
     * @param string $state 当前状态
     * @return void
     */
    protected function processQueue(array &$queue, string $state): void
    {
        $queueSnapshot = $queue;
        $queue = [];
        $processed = [];

        while (!empty($queueSnapshot)) {
            $item = array_pop($queueSnapshot);

            if ($item['state'] === $state) {
                Coroutine::go(static fn () => ($item['callback'])($state));
            } else {
                $processed[] = $item;
            }
        }

        $queue = array_reverse($processed);
    }
}
