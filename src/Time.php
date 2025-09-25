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
use Ripple\Runtime\Scheduler;
use Ripple\Sync\Channel;
use Ripple\Time\Ticker;
use Ripple\Time\Timer;
use RuntimeException;
use Throwable;

use function microtime;

/**
 * 时间工具类
 */
class Time
{
    /**
     * 阻塞当前协程指定时间
     * @param float $seconds 延迟时间 (秒)
     */
    public static function sleep(float $seconds): void
    {
        $owner = \Co\current();

        Event::timer(
            $seconds,
            0,
            static fn () => Scheduler::resume($owner)->resolve(CoroutineStateException::class)
        );

        try {
            $owner->suspend();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 返回一个channel, 在指定时间后发送当前时间
     * @param float $seconds 延迟时间 (秒)
     * @return Channel 时间到达时会发送microtime(true)
     */
    public static function after(float $seconds): Channel
    {
        $channel = new Channel(1);

        Event::timer($seconds, 0, static function (int $watchId) use ($channel) {
            $channel->send(microtime(true));
        });

        return $channel;
    }

    /**
     * 延迟执行函数, 返回可取消的定时器
     * @param float $seconds 延迟时间 (秒)
     * @param Closure $callback 要执行的函数
     * @return Timer 可取消的定时器实例
     */
    public static function afterFunc(float $seconds, Closure $callback): Timer
    {
        $watchId = Event::timer($seconds, 0, $callback);

        return new Timer($watchId);
    }

    /**
     * 创建一次性定时器
     * @param float $seconds 延迟时间 (秒)
     * @return Timer 可取消或重置的定时器实例
     */
    public static function timer(float $seconds): Timer
    {
        $channel = new Channel(1);
        $watchId = Event::timer($seconds, 0, static function (int $watchId) use ($channel) {
            $channel->send(microtime(true));
        });

        return new Timer($watchId, $channel);
    }

    /**
     * 创建周期定时器
     * @param float $seconds 周期时间 (秒)
     * @return Ticker 周期定时器实例
     */
    public static function ticker(float $seconds): Ticker
    {
        return new Ticker($seconds);
    }
}
