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
use Fiber;
use PHPUnit\Framework\MockObject\InvalidMethodNameException;
use Ripple\Runtime;
use Ripple\Runtime\Exception\CoroutineStateException;
use Ripple\Runtime\Exception\TerminateException;
use Ripple\Coroutine;
use Ripple\Runtime\Scheduler\ControlResult;
use Ripple\Runtime\Support\Display;
use Ripple\Runtime\Support\Stdin;
use SplObjectStorage;
use SplQueue;
use WeakReference;
use Throwable;

use function array_shift;
use function str_repeat;

Runtime::init();

/**
 * 调度器
 */
final class Scheduler
{
    /**
     * 可运行协程队列
     * @var SplQueue<Coroutine>
     */
    private static SplQueue $runnableQueue;

    /**
     * 协程哈希表
     * @var SplObjectStorage<object,WeakReference<Coroutine>>
     */
    private static SplObjectStorage $coroutineMap;

    /**
     * 异常控制结果队列
     * @var ControlResult[]
     */
    private static array $excepts = [];

    /**
     * 下一个事件循环中需要执行的回调队列
     * @var array
     */
    private static array $nextTicks = [];

    /**
     * 初始化调度器
     * @return void
     */
    public static function init(): void
    {
        if (isset(self::$runnableQueue)) {
            throw new InvalidMethodNameException('Runnable queue is already runnable.', );
        }

        self::$runnableQueue = new SplQueue();
        self::$coroutineMap = new SplObjectStorage();
    }

    /**
     * 调度器主循环
     * @return void
     * @throws Throwable
     */
    public static function tick(): void
    {
        do {
            if (!empty(self::$nextTicks)) {
                $nextTicks = self::$nextTicks;
                self::$nextTicks = [];

                $exports = [];
                foreach ($nextTicks as $callback) {
                    try {
                        $callback();
                    } catch (Throwable $exception) {
                        $exports[] = $exception;
                        continue;
                    }
                }

                if (!empty($exports)) {
                    throw $exports[0];
                }
            }

            // 等待事件消费
            Runtime::watcher()->tick();

            // 消费调度器内的所有协程
            Scheduler::dispatcher();

            // 主协程有事件
            if (Runtime::main()->shiftMessage()) {
                break;
            }
        } while (self::isActive());
    }

    /**
     * @return bool
     */
    public static function isActive(): bool
    {
        return self::hasQueue() || Runtime::watcher()->isActive() || !empty(self::$nextTicks);
    }

    /**
     * 提交协程到调度器
     * @param Coroutine $coroutine 协程或闭包
     * @param bool $immediate 是否立即执行
     * @return void
     */
    public static function enqueue(Coroutine $coroutine, bool $immediate = false): void
    {
        $coroutine->runnable();

        if ($coroutine->state() !== Coroutine::STATE_RUNNABLE) {
            throw CoroutineStateException::invalidState(
                $coroutine->state(),
                Coroutine::STATE_RUNNABLE,
                $coroutine,
                "Scheduler::enqueue() called with non-runnable coroutine"
            );
        }

        if ($immediate) {
            self::start($coroutine);
            return;
        }

        self::$runnableQueue->enqueue($coroutine);
    }

    /**
     * 执行一次调度循环
     * @return void
     */
    public static function dispatcher(): void
    {
        // 所有协程都会在此处开始执行, 应捕获异常以防止打坏调度器
        while ($count = self::$runnableQueue->count()) {
            for ($i = 0; $i < $count; $i++) {
                $coroutine = self::$runnableQueue->dequeue();
                self::start($coroutine);
            }
        }

        while ($report = array_shift(self::$excepts)) {
            if ($report->isResolve()) {
                continue;
            }

            $previous = $report->exception();

            Stdin::println("When self::{$report->action()} is executed, the internal Coroutine throws an unresolved exception:");
            Stdin::print(Display::exception($previous));

            Stdin::println(str_repeat('-', 60));

            Stdin::println("An uncaught exception occurs within the coroutine:");
            Stdin::print(Display::traces($report->coroutine()->debugTrace()));

            Stdin::println(str_repeat('-', 60));

            Stdin::print(Display::trace($report->debugTrace()));
        }
    }

    /**
     * 在运行时上下文执行闭包
     * @param Closure $callback 要执行的闭包
     * @return void 返回闭包执行结果或协程恢复时的值
     */
    public static function nextTick(Closure $callback): void
    {
        self::$nextTicks[] = $callback;
    }

    /**
     * 根据Fiber获取对应协程
     * @param object $key Fiber实例
     * @return ?Coroutine 协程对象, 如果未找到则返回null
     */
    public static function findCoroutine(object $key): ?Coroutine
    {
        return self::$coroutineMap[$key]->get();
    }

    /**
     * 清空调度器内部状态
     * @return void
     */
    public static function clear(): void
    {
        self::$runnableQueue = new SplQueue();
        self::$coroutineMap = new SplObjectStorage();
    }

    /**
     * 移除协程映射
     * @param object $key 要移除的协程
     * @return void
     */
    public static function remove(object $key): void
    {
        unset(self::$coroutineMap[$key]);
    }

    /**
     * 报告异常控制结果
     * @param ControlResult $controlException 异常控制结果
     * @return ControlResult 返回传入的异常控制结果
     */
    public static function reportException(ControlResult $controlException): ControlResult
    {
        self::$excepts[] = $controlException;
        return $controlException;
    }

    /**
     * 检查队列是否有可运行协程
     * @return bool 如果队列中有协程则返回true, 否则返回false
     */
    public static function hasQueue(): bool
    {
        return self::$runnableQueue->count() > 0;
    }

    /**
     * 获取可运行队列中的协程数量
     * @return int 可运行协程的数量
     */
    public static function runnableCount(): int
    {
        return self::$runnableQueue->count();
    }

    /**
     * 获取协程映射表
     * @return SplObjectStorage<Fiber,WeakReference<Coroutine>> 协程映射表
     */
    public static function coroutineMap(): SplObjectStorage
    {
        return self::$coroutineMap;
    }

    /**
     * 获取可运行协程队列
     * @return SplQueue<Coroutine> 可运行协程队列
     */
    public static function runnableQueue(): SplQueue
    {
        return self::$runnableQueue;
    }

    /**
     * 启动协程
     * @param Coroutine $coroutine 要启动的协程
     * @return void
     */
    public static function start(Coroutine $coroutine): void
    {
        $coroutine->runnable();

        self::$coroutineMap[$coroutine->key()] = WeakReference::create($coroutine);

        try {
            $coroutine->start();
        } catch (Throwable $exception) {
            new ControlResult('start', false, $coroutine, $exception);
        } finally {
            if ($coroutine->isTerminated()) {
                Scheduler::remove($coroutine->key());
                $coroutine->executeDefers();
            }
        }
    }

    /**
     * 恢复协程
     * @param Coroutine $coroutine 要恢复的协程
     * @param ?mixed $value 恢复时传入的值
     * @return ControlResult 控制结果, 包含恢复操作的结果和异常信息
     */
    public static function resume(Coroutine $coroutine, mixed $value = null): ControlResult
    {
        try {
            return new ControlResult('resume', $coroutine->resume($value), $coroutine);
        } catch (Throwable $exception) {
            return new ControlResult('resume', null, $coroutine, $exception);
        } finally {
            if ($coroutine->isTerminated()) {
                Scheduler::remove($coroutine->key());
                $coroutine->executeDefers();
            }
        }
    }

    /**
     * 向协程抛出异常
     * @param Coroutine $coroutine 要抛出异常的协程
     * @param Throwable $exception 要抛出的异常对象
     * @return ControlResult 控制结果, 包含异常抛出操作的结果和异常信息
     */
    public static function throw(Coroutine $coroutine, Throwable $exception): ControlResult
    {
        try {
            return new ControlResult('throw', $coroutine->throw($exception), $coroutine);
        } catch (Throwable $exception) {
            return new ControlResult('resume', null, $coroutine, $exception);
        } finally {
            if ($coroutine->isTerminated()) {
                Scheduler::remove($coroutine->key());
                $coroutine->executeDefers();
            }
        }
    }

    /**
     * 终止协程
     * @param Coroutine $coroutine 要终止的协程
     * @return ControlResult 控制结果, 包含终止操作的结果
     */
    public static function terminate(Coroutine $coroutine): ControlResult
    {
        if ($coroutine->state() === Coroutine::STATE_RUNNING) {
            $coroutine->onState(
                state: Coroutine::STATE_WAITING,
                callback: static fn () => self::throw($coroutine, new TerminateException()),
                prepare: true
            );

            return new ControlResult('terminate', null, $coroutine);
        }

        return self::throw($coroutine, new TerminateException());
    }
}
