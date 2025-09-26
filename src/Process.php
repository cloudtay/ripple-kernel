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

use Closure;
use Ripple\Runtime\Exception\CoroutineStateException;
use Ripple\Runtime\MainCoroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Runtime\Support\Stdin;
use Throwable;
use RuntimeException;

use function Co\wait;
use function pcntl_fork;
use function extension_loaded;
use function call_user_func;
use function pcntl_wait;
use function spl_object_hash;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_kill;

use const SIGCHLD;
use const WNOHANG;
use const WUNTRACED;

/**
 * 进程管理类
 *
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Process
{
    /**
     * 子进程 PID 列表
     * @var int[]
     */
    private static array $children = [];

    /**
     * fork 后的回调函数列表
     * @var Closure[]
     */
    private static array $forked = [];

    /**
     * 信号监听器 ID
     * @var int|null
     */
    private static ?int $watchId = null;

    /**
     * 等待子进程的协程列表
     * @var array<int, Coroutine[]>
     */
    private static array $watchers = [];

    /**
     * 创建子进程
     * 在子进程中会：
     * - 清理调度器
     * - 重置事件监听器
     * - 执行 fork 回调
     * - 执行用户回调
     *
     * @param Closure $callback 子进程执行的回调函数
     * @return int 返回子进程PID
     */
    public static function fork(Closure $callback): int
    {
        if (!extension_loaded('pcntl')) {
            return -1;
        }

        $owner = \Co\current();
        if ($owner instanceof MainCoroutine) {
            return self::spawn($callback);
        }

        Scheduler::nextTick(static function () use ($callback, $owner) {
            Scheduler::resume($owner, self::spawn($callback));
        });

        try {
            return $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param Closure $callback
     * @return int
     */
    private static function spawn(Closure $callback): int
    {
        // 子进程执行
        $pid = pcntl_fork();

        if ($pid === -1 || $pid > 0) {
            return $pid;
        }

        // 清理调度器和事件监听器
        Scheduler::clear();
        Runtime::watcher()->forked();

        // 保存并清空 fork 回调列表
        $forked = self::$forked;
        self::$forked = [];
        self::$children = [];

        // 执行所有 fork 回调
        foreach ($forked as $forkedCallback) {
            call_user_func($forkedCallback);
        }

        // 执行用户回调
        try {
            $callback();
        } catch (Throwable $e) {
            Stdin::println($e->getMessage());
        }

        // 等待所有协程完成并退出
        wait();
        exit(0);
    }

    /**
     * 等待子进程退出
     *
     * @param int $pid 子进程 PID
     * @return int 子进程退出码
     */
    public static function wait(int $pid): int
    {
        // 设置信号监听器（如果还没有设置）
        if (!self::$watchId) {
            self::$watchId = Event::watchSignal(SIGCHLD, static function () {
                while (true) {
                    $childId = pcntl_wait($status, WNOHANG | WUNTRACED);

                    if ($childId === 0) {
                        break;
                    } elseif ($childId === -1) {
                        break;
                    }

                    if (pcntl_wifexited($status)) {
                        $exitCode = pcntl_wexitstatus($status);
                        self::dispatchExit($childId, $exitCode);
                    } elseif (pcntl_wifsignaled($status)) {
                        $signal = pcntl_wtermsig($status);
                        self::dispatchExit($childId, -$signal);
                    }
                }
            });
        }

        $co = \Co\current();

        // 注册等待该子进程的协程
        if (!isset(self::$watchers[$pid])) {
            self::$watchers[$pid] = [];
        }

        self::$watchers[$pid][spl_object_hash($co)] = $co;

        try {
            return $co->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException('Child process error: ' . $e->getMessage());
        } finally {
            // 清理协程注册
            unset(self::$watchers[$pid][spl_object_hash($co)]);
            if (empty(self::$watchers[$pid])) {
                unset(self::$watchers[$pid]);
            }

            // 如果没有等待的子进程, 清理信号监听器
            if (empty(self::$watchers)) {
                Event::unwatch(self::$watchId);
                self::$watchId = null;
            }
        }
    }

    /**
     * 注册 fork 后的回调函数
     * 这些回调会在子进程中执行
     *
     * @param Closure $callback 回调函数
     * @return void
     */
    public static function forked(Closure $callback): void
    {
        self::$forked[] = $callback;
    }

    /**
     * 向制定进程发送信号
     * @param int $pid
     * @param int $signal
     * @return bool
     */
    public static function signal(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }

    /**
     * 分发子进程退出事件
     * @param int $pid 子进程 PID
     * @param int $exitCode 退出码
     * @return void
     */
    private static function dispatchExit(int $pid, int $exitCode): void
    {
        foreach (self::$watchers[$pid] ?? [] as $coroutine) {
            Scheduler::resume($coroutine, $exitCode)->resolve(CoroutineStateException::class);
        }
    }
}
