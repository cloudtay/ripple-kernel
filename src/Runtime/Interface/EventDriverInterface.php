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

namespace Ripple\Runtime\Interface;

/**
 * 事件驱动接口
 *  - 请严格遵循接口方法所描述的方式开发EventDriver, 否则可能造成资源浪费或程序崩溃
 */
interface EventDriverInterface
{
    /**
     * 运行事件循环
     * @docs 需要关心
     * 此处与 `Scheduler(协程调度器)` 轮流抢占唯一线程, 当所有协程空闲或等待资源时
     * 这里将被0延迟循环执行, 请在这里实现监听与发布协程到队列
     * @return void
     */
    public function tick(): void;

    /**
     * 检查事件驱动是否应该继续运行
     * @docs
     * 仅在事件监听器内部仍存在未完成的订阅时返回true
     * 例如:
     *   - `timerQueue` 内还有未到时间的回调订阅
     *   - `streamWatcher` 中仍有未被取消订阅的流事件监听
     *   - `signalWatcher` 中仍有绑定的信号处理器
     * 返回 false 不意味着程序结束, 不要在准备返回false时做不可逆转的资源清理,
     * 如果需要做资源清理, 请在 `__destruct` 中完成
     * @return bool true 表示继续运行；false 表示退出事件循环
     */
    public function isActive(): bool;

    /**
     * 告知事件处理器进程已经,此处应该实现对fork之后的资源清理,
     * 标准做法:
     *     - 关闭所有事件订阅
     *     - 删除所有队列中的任务
     *     - 删除所有watcher
     *     - 不干涉stream的连接状态
     * @return void
     */
    public function forked(): void;

    /**
     * stop意味着一切都将结束,清理所有资源
     * @return void
     */
    public function stop(): void;
}
