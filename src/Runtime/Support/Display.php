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

namespace Ripple\Runtime\Support;

use Ripple\Runtime;
use Throwable;

use function sprintf;
use function strtoupper;
use function str_repeat;
use function array_reverse;
use function array_slice;
use function count;
use function get_class;
use function in_array;

use const PHP_EOL;

/**
 * 调试展示工具
 */
class Display
{
    /**
     * 渲染单个栈帧数组
     * @param array $trace 栈帧数组
     * @return string 文本
     */
    public static function trace(array $trace): string
    {
        if (empty($trace)) {
            return '';
        }

        $content = '';
        $i = 0;
        $traceCount = count($trace);

        while ($i < $traceCount) {
            $currentFrame = $trace[$i];
            $currentFile = $currentFrame['file'] ?? 'unknown';
            $currentLine = $currentFrame['line'] ?? 0;

            // 推断文件类型
            $fileType = FileTypeDetector::infer($currentFile);

            if (!Runtime::$DEBUG && in_array($fileType, ['runtime','unknown'])) {
                $i++;
                continue;
            }

            // 检查连续相同文件
            $consecutiveFrames = [$currentFrame];
            $j = $i + 1;

            while ($j < $traceCount) {
                $nextFrame = $trace[$j];
                $nextFile = $nextFrame['file'] ?? 'unknown';

                if ($nextFile === $currentFile) {
                    $consecutiveFrames[] = $nextFrame;
                    $j++;
                } else {
                    break;
                }
            }

            if (count($consecutiveFrames) > 1) {
                // 连续相同文件
                $content .= sprintf(
                    "[%s] %s:%d\n",
                    $fileType,
                    $currentFile,
                    $currentLine
                );

                foreach ($consecutiveFrames as $frameIndex => $frame) {
                    $function = $frame['function'] ?? 'unknown';
                    $class = $frame['class'] ?? '';
                    $type = $frame['type'] ?? '';
                    $line = $frame['line'] ?? 0;

                    $isFirst = $frameIndex === 0;
                    $firstFrame = $isFirst ? '↑' : '|';

                    $content .= sprintf(
                        "\t%s %s%s%s() on %d \n",
                        $firstFrame,
                        $class,
                        $type,
                        $function,
                        $line
                    );
                }
            } else {
                // 单个文件
                $function = $currentFrame['function'] ?? 'unknown';
                $class = $currentFrame['class'] ?? '';
                $type = $currentFrame['type'] ?? '';

                $content .= sprintf(
                    "[%s] %s:%d -> %s%s%s() \n",
                    $fileType,
                    $currentFile,
                    $currentLine,
                    $class,
                    $type,
                    $function
                );
            }

            $i = $j;
        }

        return sprintf('%s%s', $content, PHP_EOL);
    }

    /**
     * 渲染协程跟踪信息
     * @param array $traces 跟踪记录数组
     * @param int $limit 限制输出条数
     * @return string 文本
     */
    public static function traces(array $traces, int $limit = 0): string
    {
        $traces = $limit <= 0 ? $traces : array_slice($traces, -$limit);
        $traces = array_reverse($traces);

        if (empty($traces)) {
            return '';
        }

        $content = sprintf("%s%s", str_repeat('-', 50), PHP_EOL);
        foreach ($traces as $index => $trace) {

            $content .= sprintf(
                "[%d]%s -> %s\n",
                $index,
                strtoupper($trace['state']),
                $trace['event'],
            );

            if (!empty($trace['trace'])) {
                $content .= Display::trace($trace['trace']);
            }
        }

        return $content;
    }

    /**
     * 渲染异常信息
     * @param Throwable $exception 异常对象
     * @param bool $includeTrace 是否包含堆栈跟踪
     * @return string 格式化的异常信息
     */
    public static function exception(Throwable $exception, bool $includeTrace = true): string
    {
        $content = sprintf(
            "%s:\n > %s%s",
            get_class($exception),
            $exception->getMessage(),
            PHP_EOL
        );

        $content .= sprintf(
            " > %s:%d%s",
            $exception->getFile(),
            $exception->getLine(),
            PHP_EOL
        );

        if ($exception->getCode() !== 0) {
            $content .= sprintf(" > Code: %d%s", $exception->getCode(), PHP_EOL);
        }

        if ($includeTrace && !empty($exception->getTrace())) {
            $content .= sprintf("Stack trace:%s", PHP_EOL);
            $content .= self::trace($exception->getTrace());
        }

        return $content;
    }
}
