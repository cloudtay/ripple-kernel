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

namespace Ripple\Runtime\Trait;

use Ripple\Runtime;

use function debug_backtrace;
use function microtime;
use function array_shift;
use function count;

/**
 * 调试支持
 */
trait Debugger
{
    /**
     * 调试跟踪
     * @var array<int, array{time: float, state: string, event: string, trace: array}>
     */
    public array $debugTrace = [];

    /**
     * 添加跟踪记录
     * @param string $state 协程状态
     * @param string $event 事件名称
     * @param array|null $trace
     * @return void
     */
    public function addTrace(string $state, string $event, ?array $trace = null): void
    {
        $maxTraces = Runtime::$MAX_TRACES;
        $traceData = [
            'time' => microtime(true),
            'state' => $state,
            'event' => $event,
            'trace' => $trace ?? debug_backtrace()
        ];

        if (count($this->debugTrace) >= $maxTraces) {
            array_shift($this->debugTrace);
        }

        $this->debugTrace[] = $traceData;
    }

    /**
     * 获取调试跟踪
     * @return array
     */
    public function debugTrace(): array
    {
        return $this->debugTrace;
    }

    /**
     * 清空调试跟踪
     * @return void
     */
    public function clearTrace(): void
    {
        $this->debugTrace = [];
    }
}
