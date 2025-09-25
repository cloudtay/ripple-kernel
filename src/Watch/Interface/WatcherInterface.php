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

namespace Ripple\Watch\Interface;

use Closure;

/**
 * 事件循环接口
 */
interface WatcherInterface
{
    /**
     * 创建流读取监听器
     * @param mixed $resource 要监听的流资源
     * @param Closure $callback 事件回调函数, callbackArgs (resource, watchId)
     * @return int 监听器ID
     */
    public function watchRead(mixed $resource, Closure $callback): int;

    /**
     * 创建流写入监听器
     * @param mixed $resource 要监听的流资源
     * @param Closure $callback 事件回调函数, callbackArgs (resource, watchId)
     * @return int 监听器ID
     */
    public function watchWrite(mixed $resource, Closure $callback): int;

    /**
     * 创建信号监听器
     * @param int $signal 要监听的信号
     * @param Closure $callback 信号回调函数, callbackArgs (signal, watchId)
     * @return int 监听器ID
     */
    public function watchSignal(int $signal, Closure $callback): int;

    /**
     * 创建定时器
     * @param float $after 延迟时间 (秒)
     * @param float $repeat 重复间隔 (秒)
     * @param Closure $callback 定时器回调函数, callbackArgs (watchId)
     * @return int 监听器ID
     */
    public function timer(float $after, float $repeat, Closure $callback): int;

    /**
     * 移除监听器
     * @param int $watchId 监听器ID
     * @return void
     */
    public function unwatch(int $watchId): void;
}
