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

use Closure;
use Ripple\Coroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Runtime\Support\Display;
use Ripple\Runtime\Support\Stdin;
use Ripple\Watch\Interface\WatchAbstract;
use SplMinHeap;
use InvalidArgumentException;
use Throwable;

use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function get_resource_id;
use function stream_select;
use function max;
use function is_resource;
use function usleep;
use function intdiv;

/**
 * StreamWatcher
 */
class StreamWatcher extends WatchAbstract
{
    /**
     * 监听器映射表
     * @var array<int, array{
     *     type: 'read'|'write'|'timer'|'signal',
     *     resource: mixed,
     *     callback: Closure,
     *     data: ?mixed
     * }>
     */
    private array $watchers = [];

    /**
     * 定时器堆
     * @var SplMinHeap<array{
     *     watchId: int,
     *     triggerTime: float
     * }>
     */
    private SplMinHeap $timerHeap;

    /**
     * 定时器堆模板
     * @var ?SplMinHeap<array{
     *     watchId: int,
     *     triggerTime: float
     * }>
     */
    private static ?SplMinHeap $heapTemplate = null;

    /**
     * 定时器数据
     * @var Array<int, array{
     *     callback: Closure,
     *     repeat: float,
     *     originalAfter: float
     * }>
     */
    private array $timerData = [];

    /**
     * 信号处理器
     * @var Array<int,Array<int,Closure>>
     */
    private array $signalWatchers = [];

    /**
     * 流监听器映射
     * @var array<int, array{
     *     read: array<int, Closure>,
     *     write: array<int, Closure>
     * }>
     */
    private array $streamWatchers = [];

    /**
     * 读取流数组
     * @var array<int, resource>
     */
    private array $readStreams = [];

    /**
     * 写入流数组
     * @var array<int, resource>
     */
    private array $writeStreams = [];

    /**
     * 展平流分发表
     * @var array<int, array<int, Closure>>
     */
    private array $readDispatchList = [];

    /**
     * 展平流分发表
     * @var array<int, array<int, Closure>>
     */
    private array $writeDispatchList = [];

    /**
     * 自增ID
     * @var int
     */
    private int $nextWatchId = 1;

    /**
     * 是否已停止
     * @var bool
     */
    private bool $stopped = false;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->timerHeap = $this->createTimerHeap();
    }

    /**
     * 创建定时器堆
     * @return SplMinHeap
     */
    private function createTimerHeap(): SplMinHeap
    {
        if (self::$heapTemplate === null) {
            self::$heapTemplate = new class () extends SplMinHeap {
                protected function compare($value1, $value2): int
                {
                    return $value2['triggerTime'] <=> $value1['triggerTime'];
                }
            };
        }
        return clone self::$heapTemplate;
    }


    /**
     * @inheritDoc
     */
    public function tick(): void
    {
        if ($this->stopped) {
            return;
        }

        $now = microtime(true);

        // 处理流事件
        $this->processStreams($now);

        // 处理信号
        $this->processSignals();

        // 处理定时器
        $this->processTimers($now);
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return !empty($this->watchers) || !$this->timerHeap->isEmpty() || !empty($this->signalWatchers);
    }

    /**
     * @inheritDoc
     */
    public function forked(): void
    {
        $this->clearAllWatchers();
        $this->timerHeap = $this->createTimerHeap();
        $this->nextWatchId = 1;
        $this->stopped = false;
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->clearAllWatchers();
        $this->timerHeap = $this->createTimerHeap();
    }

    /**
     * @inheritDoc
     */
    public function watchRead(mixed $resource, Closure $callback): int
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Resource must be a valid stream resource');
        }

        $watchId = $this->nextWatchId++;
        $resourceId = get_resource_id($resource);

        $this->watchers[$watchId] = [
            'type' => 'read',
            'resource' => $resource,
            'callback' => $callback,
            'data' => null
        ];

        if (!isset($this->streamWatchers[$resourceId])) {
            $this->streamWatchers[$resourceId] = ['read' => [], 'write' => []];
        }

        $this->streamWatchers[$resourceId]['read'][$watchId] = $callback;
        $this->readStreams[$resourceId] = $resource;
        $this->readDispatchList[$resourceId][$watchId] = $callback;

        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchWrite(mixed $resource, Closure $callback): int
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Resource must be a valid stream resource');
        }

        $watchId = $this->nextWatchId++;
        $resourceId = get_resource_id($resource);

        $this->watchers[$watchId] = [
            'type' => 'write',
            'resource' => $resource,
            'callback' => $callback,
            'data' => null
        ];

        if (!isset($this->streamWatchers[$resourceId])) {
            $this->streamWatchers[$resourceId] = ['read' => [], 'write' => []];
        }

        $this->streamWatchers[$resourceId]['write'][$watchId] = $callback;
        $this->writeStreams[$resourceId] = $resource;
        $this->writeDispatchList[$resourceId][$watchId] = $callback;

        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function watchSignal(int $signal, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;

        if (!isset($this->signalWatchers[$signal])) {
            $this->signalWatchers[$signal] = [];

            // 注册信号处理器
            pcntl_signal($signal, function () use ($signal) {
                if (isset($this->signalWatchers[$signal])) {
                    foreach ($this->signalWatchers[$signal] as $watchId => $callback) {
                        try {
                            //                            $callback($watchId, $signal);
                            $coroutine = Coroutine::create($callback);
                            $coroutine->bind($watchId, $signal);
                            Scheduler::enqueue($coroutine);
                        } catch (Throwable $exception) {
                            Stdin::println(Display::exception($exception));
                        }
                    }
                }
            });
        }

        $this->signalWatchers[$signal][$watchId] = $callback;
        $this->watchers[$watchId] = [
            'type' => 'signal',
            'resource' => $signal,
            'callback' => $callback,
            'data' => null
        ];

        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function timer(float $after, float $repeat, Closure $callback): int
    {
        $watchId = $this->nextWatchId++;
        $triggerTime = microtime(true) + $after;

        // 存储定时器数据
        $this->timerData[$watchId] = [
            'callback' => $callback,
            'repeat' => $repeat,
            'originalAfter' => $after
        ];

        // 添加到堆
        $this->timerHeap->insert([
            'watchId' => $watchId,
            'triggerTime' => $triggerTime
        ]);

        $this->watchers[$watchId] = [
            'type' => 'timer',
            'resource' => null,
            'callback' => $callback,
            'data' => ['repeat' => $repeat, 'originalAfter' => $after]
        ];

        return $watchId;
    }

    /**
     * @inheritDoc
     */
    public function unwatch(int $watchId): void
    {
        if (!isset($this->watchers[$watchId])) {
            return;
        }

        $watcher = $this->watchers[$watchId];

        switch ($watcher['type']) {
            case 'read':
            case 'write':
                $resource = $watcher['resource'];
                $resourceId = get_resource_id($resource);
                if (isset($this->streamWatchers[$resourceId][$watcher['type']][$watchId])) {
                    unset($this->streamWatchers[$resourceId][$watcher['type']][$watchId]);


                    if ($watcher['type'] === 'read') {
                        unset($this->readDispatchList[$resourceId][$watchId]);
                    } else {
                        unset($this->writeDispatchList[$resourceId][$watchId]);
                    }

                    // 同步流数组
                    $readEmpty = empty($this->streamWatchers[$resourceId]['read']);
                    $writeEmpty = empty($this->streamWatchers[$resourceId]['write']);

                    if ($readEmpty) {
                        unset($this->readStreams[$resourceId]);
                        unset($this->readDispatchList[$resourceId]);
                    }

                    if ($writeEmpty) {
                        unset($this->writeStreams[$resourceId]);
                        unset($this->writeDispatchList[$resourceId]);
                    }

                    // 双空则清理映射项
                    if ($readEmpty && $writeEmpty) {
                        unset($this->streamWatchers[$resourceId]);
                    }
                }
                break;

            case 'signal':
                $signal = $watcher['resource'];
                if (isset($this->signalWatchers[$signal][$watchId])) {
                    unset($this->signalWatchers[$signal][$watchId]);
                    if (empty($this->signalWatchers[$signal])) {
                        unset($this->signalWatchers[$signal]);
                    }
                }
                break;

            case 'timer':
                unset($this->timerData[$watchId]);
                break;
        }

        unset($this->watchers[$watchId]);
    }

    /**
     * 处理流事件
     * @param float $now 当前时间戳
     * @return void
     */
    private function processStreams(float $now): void
    {
        if (empty($this->readStreams) && empty($this->writeStreams)) {
            if (!$this->timerHeap->isEmpty()) {
                $delta = (int)max(700, (($this->timerHeap->top()['triggerTime'] - $now) * 1000000));
                usleep($delta);
            }

            return;
        }

        if ($this->timerHeap->isEmpty()) {
            $sec = null;
            $usec = null;
        } else {
            $delta = (int)max(0, ($this->timerHeap->top()['triggerTime'] - $now) * 1000000);
            [$sec, $usec] = [intdiv($delta, 1000000), $delta % 1000000];
        }

        $_readStreams = $this->readStreams;
        $_writeStreams = $this->writeStreams;
        $result = @stream_select($_readStreams, $_writeStreams, $_except, $sec, $usec);

        if ($result <= 0 || $result === false) {
            return;
        }

        // 可读流
        foreach ($_readStreams ?? [] as $stream) {
            $resourceId = get_resource_id($stream);
            if (isset($this->readDispatchList[$resourceId])) {
                foreach ($this->readDispatchList[$resourceId] as $watchId => $callback) {
                    try {
                        $callback($watchId, $stream);
                    } catch (Throwable $exception) {
                        Stdin::println(Display::exception($exception));
                    }
                }
            }
        }

        // 可写流
        foreach ($_writeStreams ?? [] as $stream) {
            $resourceId = get_resource_id($stream);
            if (isset($this->writeDispatchList[$resourceId])) {
                foreach ($this->writeDispatchList[$resourceId] as $watchId => $callback) {
                    try {
                        $callback($watchId, $stream);
                    } catch (Throwable $exception) {
                        Stdin::println(Display::exception($exception));
                    }
                }
            }
        }
    }

    /**
     * @return void
     */
    private function clearAllWatchers(): void
    {
        $this->watchers = [];
        $this->timerData = [];
        $this->signalWatchers = [];
        $this->streamWatchers = [];
        $this->readStreams = [];
        $this->writeStreams = [];
        $this->readDispatchList = [];
        $this->writeDispatchList = [];
    }

    /**
     * 处理信号
     * @return void
     */
    private function processSignals(): void
    {
        if (!empty($this->signalWatchers)) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * 处理定时器
     * @param float $now 当前时间戳
     * @return void
     */
    private function processTimers(float $now): void
    {
        $dueTimers = [];
        $reinsert = [];

        while (!$this->timerHeap->isEmpty()) {
            $top = $this->timerHeap->top();
            if ($top['triggerTime'] > $now) {
                break;
            }
            $this->timerHeap->extract();
            $dueTimers[] = $top;
        }

        if (empty($dueTimers)) {
            return;
        }

        foreach ($dueTimers as $timer) {
            $watchId = $timer['watchId'];

            // 可能已被其他协程序提前移除
            if (!isset($this->timerData[$watchId])) {
                continue;
            }

            $data = $this->timerData[$watchId];
            try {
                $data['callback']($watchId);
            } catch (Throwable $exception) {
                Stdin::println(Display::exception($exception));
            }

            // 降低漂移
            if ($data['repeat'] > 0) {
                $reinsert[] = [
                    'watchId' => $watchId,
                    'triggerTime' => $timer['triggerTime'] + $data['repeat']
                ];
            } else {
                $this->unwatch($watchId);
            }
        }

        foreach ($reinsert as $timer) {
            $this->timerHeap->insert($timer);
        }
    }
}
