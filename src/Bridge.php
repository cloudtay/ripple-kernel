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

use Ripple\Runtime\Scheduler;
use Throwable;

use function Co\suspend;
use function fopen;
use function sprintf;
use function unpack;
use function file_exists;
use function posix_mkfifo;
use function unlink;
use function posix_getpid;
use function sys_get_temp_dir;
use function fread;
use function fclose;

/**
 * 注册中心监听网桥
 */
class Bridge
{
    /**
     * @var int 请求ID计数器
     */
    public int $rid = 0;

    /**
     * @var Coroutine[] 请求ID到协程的映射
     */
    public array $ridToCoro = [];

    /**
     * @var mixed
     */
    public mixed $fifo;

    /**
     * @var bool
     */
    public bool $enableWatch = false;

    /**
     * @var array<int,bool> 提前到达的就绪请求
     */
    private array $readyRid = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->resetFifo();
    }

    /**
     * @var string
     */
    private string $fifoPath;

    /**
     * @return void
     */
    public function resetFifo(): void
    {
        $this->fifoPath = sprintf("%s/rip_bridge_%s.fifo", sys_get_temp_dir(), posix_getpid());

        if (!file_exists($this->fifoPath)) {
            @posix_mkfifo($this->fifoPath, 0600);
        }

        $resource = @fopen($this->fifoPath, 'r+');
        if ($resource === false) {
            $resource = @fopen($this->fifoPath, 'r');
        }

        if ($resource !== false) {
            $this->fifo = $resource;
        }
    }

    /**
     * @return string
     */
    public function fifo(): string
    {
        return $this->fifoPath;
    }

    /**
     * 为异步请求订阅当前协程, 并返回分配的请求ID
     * @return int
     */
    public function subCoroutine(): int
    {
        $owner = \Co\current();
        $reqId = ++$this->rid;
        $this->ridToCoro[$reqId] = $owner;
        $this->watchOn();
        return $reqId;
    }

    /**
     * @param int $reqId
     * @return void
     * @throws Throwable
     */
    public function await(int $reqId): void
    {
        if (isset($this->readyRid[$reqId])) {
            unset($this->readyRid[$reqId]);
            return;
        }

        suspend();
    }

    /**
     * 启用管道监听
     * @return void
     */
    public function watchOn(): void
    {
        if ($this->enableWatch) {
            return;
        }

        $this->watchId = Event::watchRead($this->fifo, fn () => $this->onReady());
        $this->enableWatch = true;
    }

    /**
     * @var int
     */
    public int $watchId = 0;

    /**
     * 停止管道监听
     * @return void
     */
    public function watchOff(): void
    {
        if (!$this->enableWatch) {
            return;
        }

        Event::unwatch($this->watchId);
        $this->enableWatch = false;
    }

    /**
     * @return void
     */
    public function onReady(): void
    {
        $ridPkg = @fread($this->fifo, 4);
        if (empty($ridPkg)) {
            return;
        }

        $arr = unpack('N', $ridPkg);
        if (empty($arr) || !isset($arr[1])) {
            return;
        }

        $id = $arr[1];

        if ($owner = $this->ridToCoro[$id] ?? null) {
            Scheduler::resume($owner);
            unset($this->ridToCoro[$id]);
        }

        if (empty($this->ridToCoro)) {
            $this->watchOff();
        }
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        fclose($this->fifo);
        $fifoPath = $this->fifo();
        file_exists($fifoPath) && unlink($fifoPath);
    }
}
