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

namespace Ripple\Net\WebSocket;

use Ripple\Net\WebSocket\Enum\Opcode;
use Ripple\Net\WebSocket\Exception\FrameException;
use Throwable;

use function chr;
use function ord;
use function pack;
use function random_bytes;
use function strlen;
use function substr;
use function unpack;

class Frame
{
    /**
     * 帧头最小长度
     */
    private const MIN_HEADER_LENGTH = 2;

    /**
     * 扩展长度 16 位
     */
    private const EXTENDED_LENGTH_16 = 126;

    /**
     * 扩展长度 64 位
     */
    private const EXTENDED_LENGTH_64 = 127;

    /**
     * 操作码
     * @var Opcode
     */
    private Opcode $opcode;

    /**
     * 负载数据
     * @var string
     */
    private string $payload;

    /**
     * 是否结束帧
     * @var bool
     */
    private bool $fin;

    /**
     * 是否有掩码
     * @var bool
     */
    private bool $masked;

    /**
     * 掩码键
     * @var string
     */
    private string $maskingKey;

    /**
     * 构造函数
     * @param Opcode $opcode 操作码
     * @param string $payload 负载数据
     * @param bool $fin 是否结束帧
     * @param bool $masked 是否有掩码
     */
    public function __construct(
        Opcode $opcode,
        string $payload = '',
        bool $fin = true,
        bool $masked = false
    ) {
        $this->opcode = $opcode;
        $this->payload = $payload;
        $this->fin = $fin;
        $this->masked = $masked;
        $this->maskingKey = '';
    }

    /**
     * 解析 WebSocket 帧数据
     * @param string $data
     * @return array<Frame>
     * @throws FrameException
     */
    public static function parse(string $data): array
    {
        [$frames] = self::parseWithConsumed($data);
        return $frames;
    }

    /**
     * 解析并返回已消费字节数
     * @return array{0: array<Frame>, 1: int}
     */
    public static function parseWithConsumed(string $data): array
    {
        $frames = [];
        $offset = 0;
        $dataLength = strlen($data);

        while ($offset < $dataLength) {
            if ($dataLength - $offset < self::MIN_HEADER_LENGTH) {
                break;
            }

            $start = $offset;
            $frame = self::parseFrame($data, $offset);
            if ($frame === null) {
                $offset = $start;
                break;
            }

            $frames[] = $frame;
        }

        return [$frames, $offset];
    }

    /**
     * 解析单个帧
     * @param string $data
     * @param int $offset
     * @return Frame|null
     * @throws FrameException
     */
    private static function parseFrame(string $data, int &$offset): ?Frame
    {
        $dataLength = strlen($data);

        if ($dataLength - $offset < self::MIN_HEADER_LENGTH) {
            return null;
        }

        $firstByte = ord($data[$offset]);
        $fin = ($firstByte & 0x80) !== 0;
        $opcode = $firstByte & 0x0F;

        $secondByte = ord($data[$offset + 1]);
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLength = $secondByte & 0x7F;

        $offset += 2;

        if ($payloadLength === self::EXTENDED_LENGTH_16) {
            if ($dataLength - $offset < 2) {
                return null; // 数据不足
            }
            $payloadLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLength === self::EXTENDED_LENGTH_64) {
            if ($dataLength - $offset < 8) {
                return null; // 数据不足
            }
            $payloadLength = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        $maskingKey = '';
        if ($masked) {
            if ($dataLength - $offset < 4) {
                return null; // 数据不足
            }
            $maskingKey = substr($data, $offset, 4);
            $offset += 4;
        }

        if ($dataLength - $offset < $payloadLength) {
            return null; // 数据不足
        }

        $payload = substr($data, $offset, $payloadLength);
        $offset += $payloadLength;

        if ($masked && $maskingKey !== '') {
            $payload = self::applyMask($payload, $maskingKey);
        }

        $frame = new Frame(Opcode::fromValue($opcode), $payload, $fin, $masked);
        $frame->maskingKey = $maskingKey;

        return $frame;
    }

    /**
     * 应用掩码
     * @param string $payload
     * @param string $maskingKey
     * @return string
     */
    private static function applyMask(string $payload, string $maskingKey): string
    {
        $result = '';
        $keyLength = strlen($maskingKey);

        for ($i = 0; $i < strlen($payload); $i++) {
            $result .= $payload[$i] ^ $maskingKey[$i % $keyLength];
        }

        return $result;
    }

    /**
     * 转换为字节
     * @return string
     * @throws Throwable
     */
    public function toBytes(): string
    {
        $payloadLength = strlen($this->payload);
        $header = '';

        $firstByte = ($this->fin ? 0x80 : 0x00) | $this->opcode->value;
        $header .= chr($firstByte);

        if ($payloadLength < self::EXTENDED_LENGTH_16) {
            $secondByte = $payloadLength | ($this->masked ? 0x80 : 0x00);
            $header .= chr($secondByte);
        } elseif ($payloadLength < 65536) {
            $header .= chr(self::EXTENDED_LENGTH_16 | ($this->masked ? 0x80 : 0x00));
            $header .= pack('n', $payloadLength);
        } else {
            $header .= chr(self::EXTENDED_LENGTH_64 | ($this->masked ? 0x80 : 0x00));
            $header .= pack('J', $payloadLength);
        }

        if ($this->masked) {
            if ($this->maskingKey === '') {
                $this->maskingKey = self::generateMaskingKey();
            }
            $header .= $this->maskingKey;
        }

        $payload = $this->payload;
        if ($this->masked && $this->maskingKey !== '') {
            $payload = self::applyMask($payload, $this->maskingKey);
        }

        return $header . $payload;
    }

    /**
     * 生成掩码键
     * @return string
     * @throws Throwable
     */
    private static function generateMaskingKey(): string
    {
        return random_bytes(4);
    }

    /**
     * 获取操作码
     * @return Opcode
     */
    public function opcode(): Opcode
    {
        return $this->opcode;
    }

    /**
     * 获取负载数据
     * @return string
     */
    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * 是否结束帧
     * @return bool
     */
    public function isFin(): bool
    {
        return $this->fin;
    }

    /**
     * 是否有掩码
     * @return bool
     */
    public function isMasked(): bool
    {
        return $this->masked;
    }
}
