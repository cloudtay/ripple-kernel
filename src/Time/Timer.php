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

namespace Ripple\Time;

use Ripple\Event;
use Ripple\Sync\Channel;

use function microtime;

/**
 * 定时器
 */
class Timer
{
    /**
     * @var int 监听器ID
     */
    private int $watchId;

    /**
     * @var ?Channel 通道
     */
    private ?Channel $channel;

    /**
     * @var bool 是否已停止
     */
    private bool $stopped = false;

    /**
     * @param int $watchId
     * @param ?Channel $channel
     */
    public function __construct(int $watchId, ?Channel $channel = null)
    {
        $this->watchId = $watchId;
        $this->channel = $channel;
    }

    /**
     * 停止定时器
     * @return bool 是否成功停止
     */
    public function stop(): bool
    {
        if ($this->stopped) {
            return false;
        }

        Event::unwatch($this->watchId);
        $this->stopped = true;
        return true;
    }

    /**
     * 重置定时器
     * @param float $seconds 延迟时间 (秒)
     * @return bool 是否成功重置
     */
    public function reset(float $seconds): bool
    {
        if ($this->stopped) {
            return false;
        }

        Event::unwatch($this->watchId);

        if ($this->channel) {
            $channel = $this->channel;
            $this->watchId = Event::timer($seconds, 0, static function ($watchId) use ($channel) {
                $channel->send(microtime(true));
            });
        } else {
            $this->watchId = Event::timer($seconds, 0, static fn ($watchId) => null);
        }

        return true;
    }

    /**
     * 获取关联的 channel
     * @return ?Channel
     */
    public function channel(): ?Channel
    {
        return $this->channel;
    }

    /**
     * 定时器是否已停止
     * @return bool
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
