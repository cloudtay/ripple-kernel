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

namespace Ripple\Stream;

use InvalidArgumentException;
use OutOfBoundsException;

use function min;
use function max;
use function substr;
use function strlen;
use function str_repeat;

/**
 * 环形缓冲区
 * 数据完整性和规范性第一, 性能次要, PHP没直接在Zend上做0拷贝操作的手段
 * 这个API的移植只为解决长内容传输场景的数据一致性
 */
class RingBuffer
{
    /** @var string 内部缓冲区 */
    private string $buffer;

    /** @var int 读指针 */
    private int $readPos = 0;

    /** @var int 写指针 */
    private int $writePos = 0;

    /** @var int 缓冲区容量 */
    private int $capacity;

    /** @var int 当前数据长度 */
    private int $length = 0;

    /** @var int 最小容量 */
    private const MIN_CAPACITY = 1024;

    /** @var int 最大容量 */
    private const MAX_CAPACITY = 16 * 1024 * 1024; // 16MB

    /** @var float 扩容因子 */
    private const GROWTH_FACTOR = 1.5;

    /** @var float 压缩阈值 */
    private const COMPACT_THRESHOLD = 0.25;

    /**
     * 构造函数
     * @param int $initialCapacity 初始容量
     */
    public function __construct(int $initialCapacity = self::MIN_CAPACITY)
    {
        if ($initialCapacity < self::MIN_CAPACITY) {
            $initialCapacity = self::MIN_CAPACITY;
        }

        if ($initialCapacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException("Initial capacity exceeds maximum allowed: " . self::MAX_CAPACITY);
        }

        // 容量调整为2的幂次
        $this->capacity = $this->nextPowerOfTwo($initialCapacity);
        $this->buffer = str_repeat("\0", $this->capacity);
    }

    /**
     * 写入数据到缓冲区
     * @param string $data 要写入的数据
     * @return int 实际写入的字节数
     */
    public function write(string $data): int
    {
        $dataLength = strlen($data);
        if ($dataLength === 0) {
            return 0;
        }

        // 检查是否需要扩容
        $availableSpace = $this->capacity - $this->length;
        if ($dataLength > $availableSpace) {
            $this->expandToFit($dataLength);
        }

        // 允许写入的总字节数
        $writable = min($dataLength, $this->capacity - $this->length);
        if ($writable === 0) {
            return 0;
        }

        // 第一段：从 writePos 到缓冲区末尾的连续空间
        $firstSegment = min($writable, $this->capacity - $this->writePos);
        if ($firstSegment > 0) {
            $chunk = substr($data, 0, $firstSegment);
            for ($i = 0; $i < $firstSegment; $i++) {
                $this->buffer[$this->writePos + $i] = $chunk[$i];
            }
        }

        // 第二段：从缓冲区开头开始写剩余数据
        $secondSegment = $writable - $firstSegment;
        if ($secondSegment > 0) {
            $chunk = substr($data, $firstSegment, $secondSegment);
            for ($i = 0; $i < $secondSegment; $i++) {
                $this->buffer[$i] = $chunk[$i];
            }
        }

        $this->writePos = ($this->writePos + $writable) % $this->capacity;
        $this->length += $writable;

        return $writable;
    }

    /**
     * 从缓冲区读取数据
     * @param int $length 要读取的字节数
     * @return string 读取的数据
     */
    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $readable = min($length, $this->length);
        if ($readable === 0) {
            return '';
        }

        // 第一段：从 readPos 到缓冲区末尾
        $firstSegment = min($readable, $this->capacity - $this->readPos);
        $result = '';
        if ($firstSegment > 0) {
            $result = substr($this->buffer, $this->readPos, $firstSegment);
        }

        // 第二段：从缓冲区开始补足剩余
        $secondSegment = $readable - $firstSegment;
        if ($secondSegment > 0) {
            $result .= substr($this->buffer, 0, $secondSegment);
        }

        $this->readPos = ($this->readPos + $readable) % $this->capacity;
        $this->length -= $readable;

        // 检查是否需要压缩
        if ($this->shouldCompact()) {
            $this->compact();
        }

        return $result;
    }

    /**
     * 查看缓冲区数据但不移除
     * @param int $length 要查看的字节数
     * @return string 查看的数据
     */
    public function peek(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $peekLength = min($length, $this->length);
        if ($peekLength === 0) {
            return '';
        }

        $firstSegment = min($peekLength, $this->capacity - $this->readPos);
        $result = '';
        if ($firstSegment > 0) {
            $result = substr($this->buffer, $this->readPos, $firstSegment);
        }
        $secondSegment = $peekLength - $firstSegment;
        if ($secondSegment > 0) {
            $result .= substr($this->buffer, 0, $secondSegment);
        }

        return $result;
    }

    /**
     * 获取缓冲区中的数据长度
     * @return int 数据长度
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * 检查缓冲区是否为空
     * @return bool 是否为空
     */
    public function isEmpty(): bool
    {
        return $this->length === 0;
    }

    /**
     * 清空缓冲区
     * @return void
     */
    public function clear(): void
    {
        $this->readPos = 0;
        $this->writePos = 0;
        $this->length = 0;
    }

    /**
     * 获取缓冲区容量
     * @return int 容量
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * 压缩缓冲区
     * @return void
     */
    public function compact(): void
    {
        if ($this->length === 0) {
            $this->clear();
            return;
        }

        // 数据是连续的, 不需要重新排列
        if ($this->readPos < $this->writePos) {
            return;
        }

        // 重新排列数据, 应对其连续
        $newBuffer = str_repeat("\0", $this->capacity);
        $copied = 0;

        // 复制从readPos到缓冲区末尾的数据
        $firstPart = $this->capacity - $this->readPos;
        $firstPartLength = min($firstPart, $this->length);

        for ($i = 0; $i < $firstPartLength; $i++) {
            $newBuffer[$copied + $i] = $this->buffer[$this->readPos + $i];
        }
        $copied += $firstPartLength;

        // 复制从缓冲区开始到writePos的数据
        if ($copied < $this->length) {
            $secondPartLength = $this->length - $copied;
            for ($i = 0; $i < $secondPartLength; $i++) {
                $newBuffer[$copied + $i] = $this->buffer[$i];
            }
        }

        $this->buffer = $newBuffer;
        $this->readPos = 0;
        $this->writePos = $this->length;
    }

    /**
     * 扩展缓冲区容量
     * @param int $newCapacity 新容量
     * @return void
     */
    public function expand(int $newCapacity): void
    {
        if ($newCapacity <= $this->capacity) {
            return;
        }

        if ($newCapacity > self::MAX_CAPACITY) {
            throw new OutOfBoundsException("New capacity exceeds maximum allowed: " . self::MAX_CAPACITY);
        }

        $newCapacity = $this->nextPowerOfTwo($newCapacity);
        $this->expandBuffer($newCapacity);
    }

    /**
     * 扩容以适应指定大小的数据
     * @param int $dataSize 数据大小
     * @return void
     */
    private function expandToFit(int $dataSize): void
    {
        $requiredCapacity = $this->length + $dataSize;
        $newCapacity = max(
            (int)($this->capacity * self::GROWTH_FACTOR),
            $requiredCapacity
        );

        if ($newCapacity > self::MAX_CAPACITY) {
            $newCapacity = self::MAX_CAPACITY;
        }

        $this->expand($newCapacity);
    }

    /**
     * 扩展缓冲区
     * @param int $newCapacity 新容量
     * @return void
     */
    private function expandBuffer(int $newCapacity): void
    {
        $newBuffer = str_repeat("\0", $newCapacity);

        if ($this->length > 0) {
            // 现有数据复制到新缓冲区
            if ($this->readPos < $this->writePos) {
                // 数据是连续的
                for ($i = 0; $i < $this->length; $i++) {
                    $newBuffer[$i] = $this->buffer[$this->readPos + $i];
                }
            } else {
                // 数据是分段的
                $firstPart = $this->capacity - $this->readPos;
                $firstPartLength = min($firstPart, $this->length);

                for ($i = 0; $i < $firstPartLength; $i++) {
                    $newBuffer[$i] = $this->buffer[$this->readPos + $i];
                }

                if ($firstPartLength < $this->length) {
                    $secondPartLength = $this->length - $firstPartLength;
                    for ($i = 0; $i < $secondPartLength; $i++) {
                        $newBuffer[$firstPartLength + $i] = $this->buffer[$i];
                    }
                }
            }
        }

        $this->buffer = $newBuffer;
        $this->capacity = $newCapacity;
        $this->readPos = 0;
        $this->writePos = $this->length;
    }

    /**
     * 检查是否应该压缩
     * @return bool 是否应该压缩
     */
    private function shouldCompact(): bool
    {
        return $this->length > 0 &&
               $this->length < ($this->capacity * self::COMPACT_THRESHOLD) &&
               ($this->readPos >= $this->writePos);
    }

    /**
     * 获取下一个2的幂次
     * @param int $value 值
     * @return int 2的幂次
     */
    private function nextPowerOfTwo(int $value): int
    {
        $power = 1;
        while ($power < $value) {
            $power <<= 1;
        }
        return $power;
    }
}
