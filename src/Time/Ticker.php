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
 * 周期定时器
 */
class Ticker
{
    /**
     * @var int 监听器ID
     */
    private int $watchId;

    /**
     * @var Channel 通道
     */
    private Channel $channel;

    /**
     * @var bool 是否已停止
     */
    private bool $stopped = false;

    /**
     * @param float $seconds
     */
    public function __construct(float $seconds)
    {
        $this->channel = new Channel(0); // 无缓冲 channel

        $channel = $this->channel;
        $this->watchId = Event::timer(0, $seconds, static function ($watchId) use ($channel) {
            $channel->send(microtime(true));
        });
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
     * 获取关联的 channel
     * @return Channel
     */
    public function channel(): Channel
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
