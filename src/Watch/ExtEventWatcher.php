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
use Ripple\Runtime\Support\Stdin;
use Ripple\Watch\Interface\WatchAbstract;
use Ripple\Watch\Interface\WatcherInterface;
use Closure;
use Throwable;
use Event;
use EventBase;
use RuntimeException;

use function extension_loaded;

/**
 * 基于 ext-event 的事件驱动
 */
final class ExtEventWatcher extends WatchAbstract implements WatcherInterface, EventDriverInterface
{
    /**
     * event 事件循环
     * @var EventBase
     * @phpstan-ignore-next-line
     */
    private EventBase $base;

    /**
     * 监听器映射表
     * @var array<int, Event>
     */
    private array $watchers = [];

    /**
     * 内建SIGCHLD
     * @var Event|null
     */
    private ?Event $internalSigchld = null;

    /**
     * SIGCHLD
     * @var array<int, Closure>
     */
    private array $sigchldWatchers = [];

    /**
     * 下一个监听器ID
     * @var int
     */
    private int $nextWatchId = 1;

    /**
     * 构造函数
     */
    public function __construct()
    {
        if (!extension_loaded('event')) {
            throw new RuntimeException('ext-event extension is required for EventWatcher');
        }
        $this->base = new EventBase();
    }

    /**
     * @inheritDoc
     */
    public function tick(): void
    {
        $this->base->loop(EventBase::LOOP_ONCE);
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
        $this->base->reInit();
        $this->watchers = [];
        $this->nextWatchId = 1;

        $this->base = new EventBase();

        $this->sigchldWatchers = [];
        $this->internalSigchld = null;
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->free();
        }
        $this->watchers = [];
        if ($this->internalSigchld) {
            $this->internalSigchld->free();
            $this->internalSigchld = null;
        }
        $this->base->exit();
    }

    /**
     * @inheritDoc
     */
    public function watchRead(mixed $resource, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;

        $watcher = new Event($this->base, $resource, Event::READ | Event::PERSIST, function ($fd, $what, $arg) use ($callback, $watchId) {
            try {
                $callback($watchId, $fd);
            } catch (Throwable $exception) {
                Stdin::println($exception->getMessage());
            }
        });

        $this->watchers[$watchId] = $watcher;
        $watcher->add();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchWrite(mixed $resource, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;

        $watcher = new Event($this->base, $resource, Event::WRITE | Event::PERSIST, function ($fd, $what, $arg) use ($callback, $watchId) {
            try {
                $callback($watchId, $fd);
            } catch (Throwable $exception) {
                Stdin::println($exception->getMessage());
            }
        });

        $this->watchers[$watchId] = $watcher;
        $watcher->add();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchSignal(int $signal, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;

        $watcher = new Event($this->base, $signal, Event::SIGNAL | Event::PERSIST, function ($fd, $what, $arg) use ($callback, $watchId, $signal) {
            try {
                $callback($signal, $watchId);
            } catch (Throwable $exception) {
                Stdin::println($exception->getMessage());
            }
        });

        $this->watchers[$watchId] = $watcher;
        $watcher->add();
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function timer(float $after, float $repeat, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;

        $flags = Event::TIMEOUT;
        if ($repeat > 0) {
            $flags |= Event::PERSIST;
        }

        $timer = new Event($this->base, -1, $flags, function ($fd, $what, $arg) use ($callback, $watchId, $repeat) {
            if ($repeat <= 0) {
                if (isset($this->watchers[$watchId])) {
                    $this->watchers[$watchId]->free();
                    unset($this->watchers[$watchId]);
                }
            }

            try {
                $callback($watchId);
            } catch (Throwable $exception) {
                Stdin::println($exception->getMessage());
            }
        });

        $this->watchers[$watchId] = $timer;
        $timer->add($after);
        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function unwatch(int $watchId): void
    {
        if ($watcher = $this->watchers[$watchId] ?? null) {
            $watcher->free();
            unset($this->watchers[$watchId]);
            return;
        }

        if (isset($this->sigchldWatchers[$watchId])) {
            unset($this->sigchldWatchers[$watchId]);
        }
    }
}
