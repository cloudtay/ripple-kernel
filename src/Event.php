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
use Ripple\Watch\Interface\WatchAbstract;

Runtime::init();

/**
 * @method static int watchRead(mixed $resource, Closure $callback) 创建流读取监听器
 * @method static int watchWrite(mixed $resource, Closure $callback) 创建流写入监听器
 * @method static int watchSignal(int $signal, Closure $callback) 创建信号监听器
 * @method static int timer(float $after, float $repeat, Closure $callback) 创建定时器
 * @method static void unwatch(int $watchId) 移除监听器
 */
class Event
{
    /**
     * @var WatchAbstract
     */
    private static WatchAbstract $watchAbstract;

    /**
     * @param WatchAbstract $watchAbstract
     * @return void
     */
    public static function init(WatchAbstract $watchAbstract): void
    {
        self::$watchAbstract = $watchAbstract;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::$watchAbstract->$name(...$arguments);
    }
}
