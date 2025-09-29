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

namespace Ripple\Watch;

use Ripple\Runtime\Interface\EventDriverInterface;
use Ripple\Runtime\Support\Display;
use Ripple\Runtime\Support\Stdin;
use Ripple\Watch\Interface\WatchAbstract;
use Ripple\Watch\Interface\WatcherInterface;
use Closure;
use Ev;
use EvLoop;
use Throwable;

/**
 * ExtEv扩展驱动
 */
final class ExtEvWatcher extends WatchAbstract implements WatcherInterface, EventDriverInterface
{
    /**
     * 事件循环
     * @var EvLoop
     */
    private EvLoop $loop;

    /**
     * 监听器映射表
     * @var array<int, mixed>
     */
    private array $watchers = [];

    /**
     * 下一个监听器ID
     * @var int
     */
    private int $nextWatchId = 1;

    /**
     *
     */
    public function __construct()
    {
        $this->loop = new EvLoop();
    }

    /**
     * @inheritDoc
     */
    public function tick(): void
    {
        $this->loop->run(Ev::RUN_ONCE);
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return !empty($this->watchers);
    }

    /**
     * @inheritDoc
     */
    public function forked(): void
    {
        $this->loop->loopFork();
        $this->watchers = [];
        $this->nextWatchId = 1;
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->stop();
        }
        $this->watchers = [];
        $this->loop->stop();
    }

    /**
     * @inheritDoc
     */
    public function watchRead(mixed $resource, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;
        $watcher = $this->loop->io($resource, Ev::READ, static function ($w) use ($callback, $watchId) {
            try {
                $callback($watchId, $w->data);
            } catch (Throwable $exception) {
                Stdin::println(Display::exception($exception));
            }
        }, $resource);

        $this->watchers[$watchId] = $watcher;
        $watcher->start();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchWrite(mixed $resource, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;
        $watcher = $this->loop->io($resource, Ev::WRITE, static function ($w) use ($callback, $watchId) {
            try {
                $callback($watchId, $w->data);
            } catch (Throwable $exception) {
                Stdin::println(Display::exception($exception));
            }
        }, $resource);

        $this->watchers[$watchId] = $watcher;
        $watcher->start();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function timer(float $after, float $repeat, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;
        $watcher = $this->loop->timer($after, $repeat, function ($watcher) use ($watchId, $callback, $repeat) {
            // 一次性
            if ($repeat <= 0) {
                $watcher->stop();
                unset($this->watchers[$watchId]);
            }

            try {
                $callback($watchId);
            } catch (Throwable $exception) {
                Stdin::println(Display::exception($exception));
            }
        });

        $this->watchers[$watchId] = $watcher;
        $watcher->start();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchSignal(int $signal, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;
        $watcher = $this->loop->signal($signal, static function ($w) use ($callback, $watchId) {
            try {
                $callback($watchId, $w->signum);
            } catch (Throwable $exception) {
                Stdin::println(Display::exception($exception));
            }
        });

        $this->watchers[$watchId] = $watcher;
        $watcher->start();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function unwatch(int $watchId): void
    {
        if (!$watcher = $this->watchers[$watchId] ?? null) {
            return;
        }

        $watcher->stop();
        unset($this->watchers[$watchId]);
    }
}
