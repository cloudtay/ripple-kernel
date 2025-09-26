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

use Fiber;
use Ripple\Runtime\FiberCoroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Runtime\Trait\Debugger;
use Ripple\Runtime\Trait\StateMachine;
use Throwable;
use Closure;

use function spl_object_id;

/**
 * 接口
 */
abstract class Coroutine
{
    use StateMachine;

    use Debugger;

    /** 已创建状态 */
    public const STATE_CREATED = 'STATE_CREATED';

    /** 可运行状态 */
    public const STATE_RUNNABLE = 'STATE_RUNNABLE';

    /** 正在运行状态 */
    public const STATE_RUNNING = 'STATE_RUNNING';

    /** 等待状态 */
    public const STATE_WAITING = 'STATE_WAITING';

    /** 已终止状态 */
    public const STATE_DEAD = 'STATE_DEAD';

    /**
     * 参数数组
     * @var array<int, mixed>
     */
    protected array $args = [];

    /**
     * 运行结果
     * @var mixed
     */
    protected mixed $result = null;

    /**
     * 延迟执行队列
     * @var array<int, Closure>
     */
    protected array $defers = [];

    /**
     * defer 是否已执行标记
     * @var bool
     */
    protected bool $defersExecuted = false;

    /**
     * 创建协程
     * @param Closure $callback
     */
    public function __construct(protected Closure $callback)
    {
        $this->setState(Coroutine::STATE_CREATED);
    }

    /**
     * 获取协程标识(在回收复用后会被改变)
     * @return object Fiber实例的唯一标识
     */
    abstract public function key(): object;

    /**
     * 设置协程为可运行状态
     * @return void
     */
    abstract public function runnable(): void;

    /**
     * 启动协程执行
     * @return mixed 协程执行结果
     */
    abstract public function start(): mixed;

    /**
     * 暂停协程执行
     * @param mixed $value 暂停时传递的值
     * @return mixed 恢复时传入的值
     * @throws Throwable 当协程暂停过程中发生异常时抛出
     */
    abstract public function suspend(mixed $value = null): mixed;

    /**
     * 恢复协程执行
     * @param mixed $value 恢复时传递的值
     * @return mixed 协程恢复后的返回值
     */
    abstract public function resume(mixed $value = null): mixed;

    /**
     * 向协程抛出异常
     * @param Throwable $exception 要抛出的异常对象
     * @return mixed 异常处理后的返回值
     */
    abstract public function throw(Throwable $exception): mixed;

    /**
     * 回收协程资源
     * @param Closure|null $callback
     * @return void
     */
    abstract public function recycle(?Closure $callback = null): void;

    /**
     * @return int
     */
    public function id(): int
    {
        return spl_object_id($this);
    }

    /**
     * 绑定参数到协程
     * @param mixed ...$args 要绑定的参数列表
     * @return void
     */
    public function bind(mixed ...$args): void
    {
        $this->args = $args;
    }

    /**
     * 获取协程执行结果
     * @return mixed 协程执行结果
     */
    public function result(): mixed
    {
        return $this->result;
    }

    /**
     * 创建并启动协程
     * @param Closure $callback 协程回调函数
     * @return Coroutine 创建的协程实例
     */
    public static function go(Closure $callback): Coroutine
    {
        $coroutine = self::create($callback);
        Scheduler::enqueue($coroutine, true);
        return $coroutine;
    }

    /**
     * 创建协程实例
     * @param Closure $callback 协程回调函数
     * @return Coroutine 创建的协程实例
     */
    public static function create(Closure $callback): Coroutine
    {
        return new FiberCoroutine($callback);
    }

    /**
     * 获取当前协程
     * @return Coroutine 当前协程实例
     */
    public static function current(): Coroutine
    {
        if (!Fiber::getCurrent()) {
            return Runtime::main();
        }

        return Scheduler::findCoroutine(Fiber::getCurrent());
    }

    /**
     * 执行defer回调
     * @return void
     */
    public function executeDefers(): void
    {
        if ($this->defersExecuted) {
            return;
        }

        $this->defersExecuted = true;

        foreach ($this->defers as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                // 忽略 defer 回调中的异常
            }
        }
    }
}
