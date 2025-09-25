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
use Composer\Autoload\ClassLoader;
use ReflectionClass;
use Ripple\Runtime\MainCoroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Watch\WatcherFactory;
use Ripple\Watch\Interface\WatchAbstract;
use RuntimeException;
use Throwable;

use function call_user_func;
use function define;
use function dirname;
use function ini_set;
use function gc_disable;

ini_set('max_execution_time', 0);
ini_set('memory_limit', -1);
define("__RIPPLE_RUNTIME_PKG_PATH", Runtime::DIR);
define("__RIPPLE_VENDOR_PATH", dirname((new ReflectionClass(ClassLoader::class))->getFileName()));
define("__RIPPLE_PKG_PATH", dirname(__RIPPLE_VENDOR_PATH));

gc_disable();

Runtime::init();

/**
 * 运行时管理器
 */
final class Runtime
{
    /** 运行时源代码目录 */
    public const DIR = __DIR__;

    /** 自定义EventLoop */
    public static ?string $WATCHER = null;

    /** 调试追踪的最大条数 */
    public static int $MAX_TRACES = 20;

    /** 是否启用调试输出 */
    public static bool $DEBUG = false;

    /** 静态链接库 */
    public static bool $XXX = false;

    /** 全局运行时协程实例 */
    private static MainCoroutine $runtimeMain;

    /** 事件桥 */
    private static Bridge $bridge;

    /** 事件监听器 */
    private static WatchAbstract $watcher;

    /**
     * 获取全局 Runtime 实例
     * @return void
     */
    public static function init(): void
    {
        if (isset(self::$watcher)) {
            return;
        }

        self::$watcher = WatcherFactory::create();
        self::$runtimeMain = new MainCoroutine(static fn () => Scheduler::tick());

        if (self::$XXX) {
            self::$bridge = new Bridge();
        }

        Event::init(self::$watcher);
        Scheduler::init();
    }

    /**
     * 获取运行时协程实例
     * @return MainCoroutine
     */
    public static function main(): MainCoroutine
    {
        return self::$runtimeMain;
    }

    /**
     * 获取事件驱动
     * @return WatchAbstract
     */
    public static function watcher(): WatchAbstract
    {
        return self::$watcher;
    }

    /**
     * 获取事件桥
     * @return Bridge
     */
    public static function bridge(): Bridge
    {
        return self::$bridge;
    }

    /**
     * 运行回调并进入主循环
     * @param Closure|null $callback
     * @return void
     */
    public static function run(?Closure $callback = null): void
    {
        $callback && Coroutine::go(static fn () => call_user_func($callback));

        try {
            self::$runtimeMain->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
