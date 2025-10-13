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

namespace Ripple\Net\WebSocket\Enum;

use InvalidArgumentException;

enum Opcode: int
{
    case CONTINUATION = 0x0;  // 继续帧
    case TEXT = 0x1;         // 文本帧
    case BINARY = 0x2;       // 二进制帧
    case CLOSE = 0x8;        // 关闭帧
    case PING = 0x9;         // ping 帧
    case PONG = 0xA;         // pong 帧

    /**
     * 从整数值创建枚举
     * @param int $value
     * @return self
     * @throws InvalidArgumentException
     */
    public static function fromValue(int $value): self
    {
        return match ($value) {
            0x0 => self::CONTINUATION,
            0x1 => self::TEXT,
            0x2 => self::BINARY,
            0x8 => self::CLOSE,
            0x9 => self::PING,
            0xA => self::PONG,
            default => throw new InvalidArgumentException("Invalid opcode: {$value}")
        };
    }

    /**
     * 是否为控制帧
     * @return bool
     */
    public function isControlFrame(): bool
    {
        return match ($this) {
            self::CLOSE, self::PING, self::PONG => true,
            default => false
        };
    }

    /**
     * 是否为数据帧
     * @return bool
     */
    public function isDataFrame(): bool
    {
        return match ($this) {
            self::TEXT, self::BINARY => true,
            default => false
        };
    }
}
