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
use SplQueue;

use function Co\go;
use function call_user_func;

/**
 * 热协程池
 */
final class HotCoroutinePool
{
    /**
     * 空闲协程队列
     * @var SplQueue<Coroutine>
     */
    private SplQueue $coroutines;

    /**
     * 构造函数
     * @param Closure $process 协程要执行的任务处理函数
     * @param int $size 池的最大容量
     */
    public function __construct(private readonly Closure $process, private readonly int $size = 100)
    {
        $this->coroutines = new SplQueue();
        for ($i = 0;$i < $this->size;$i++) {
            $this->acquire();
        }
    }

    /**
     * 获取一个协程来处理任务
     * 若池子里没有则创建新的协程
     *
     * @return Coroutine
     */
    public function acquire(): Coroutine
    {
        if ($this->coroutines->isEmpty()) {
            return $coroutine = go(function () use (&$coroutine) {
                while (1) {
                    call_user_func($this->process);
                    $this->release($coroutine);
                }
            });
        }

        return $this->coroutines->dequeue();
    }

    /**
     * 将协程放回池中等待下次使用
     * 如果池已满, 则拒绝放入
     * @param Coroutine $coroutine 要放回池中的协程
     * @return bool
     */
    public function release(Coroutine $coroutine): bool
    {
        if ($this->coroutines->count() >= $this->size) {
            return false;
        }

        $this->coroutines->enqueue($coroutine);
        return true;
    }

    /**
     * 获取池中空闲协程的数量
     * @return int
     */
    public function count(): int
    {
        return $this->coroutines->count();
    }

    /**
     * 清空协程池并终止所有协程
     * @return void
     */
    public function clear(): void
    {
        while (!$this->isEmpty()) {
            $coroutine = $this->acquire();
            Scheduler::terminate($coroutine)->resolve();
        }

        $this->coroutines = new SplQueue();
    }

    /**
     * 检查协程池是否为空
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->coroutines->isEmpty();
    }
}
