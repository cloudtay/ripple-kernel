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

use Psr\Http\Message\StreamInterface;
use Ripple\Stream\Exception\ConnectionException;

use function fclose;
use function feof;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function is_resource;
use function rewind;
use function stream_get_contents;
use function stream_get_meta_data;
use function boolval;

use const SEEK_SET;

/**
 * 基础流实现,实现PSR7标准并将异常转化为框架内部异常
 */
class BaseStream implements StreamInterface
{
    /**
     * @var resource 底层流资源
     */
    public readonly mixed $resource;

    /**
     * @var bool
     */
    private bool $closed = false;

    /**
     * @param resource $resource 底层资源
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * 读取
     * @param int $length 字节数
     * @return string 内容
     * @throws ConnectionException
     */
    public function read(int $length): string
    {
        $content = @fread($this->resource, $length);

        if ($content === false) {
            throw new ConnectionException('Unable to read from stream');
        }

        return $content;
    }

    /**
     * 关闭
     * @return void
     */
    public function close(): void
    {
        if ($this->closed || !is_resource($this->resource)) {
            return;
        }

        fclose($this->resource);
        $this->closed = true;
    }

    /**
     * 写入
     * @param string $string 内容
     * @return int 已写入字节
     * @throws ConnectionException
     */
    public function write(string $string): int
    {
        $result = @fwrite($this->resource, $string);
        if ($result === false) {
            throw new ConnectionException('Unable to write to stream');
        }

        return $result;
    }

    /**
    * 是否到达末尾
    * @return bool
    */
    public function eof(): bool
    {
        return feof($this->resource);
    }

    /**
     * 移动指针到指定位置
     * @param int $offset 偏移
     * @param int $whence 参照
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        fseek($this->resource, $offset, $whence);
    }

    /**
     * 重置指针到起始
     * @return void
     */
    public function rewind(): void
    {
        rewind($this->resource);
    }

    /**
     * 分离资源
     * @return mixed 资源或 null
     */
    public function detach(): mixed
    {
        if (!isset($this->resource)) {
            return null;
        }

        $result = $this->resource;
        unset($this->resource);
        return $result;
    }

    /**
     * 获取大小
     * @return ?int 字节数
     */
    public function getSize(): ?int
    {
        $stats = fstat($this->resource);
        return $stats['size'];
    }

    /**
     * 获取当前位置
     * @return int 偏移
     */
    public function tell(): int
    {
        return ftell($this->resource);
    }

    /**
     * 是否可寻址
     * @return bool
     */
    public function isSeekable(): bool
    {
        return boolval($this->getMetadata('seekable')) ?? false;
    }

    /**
     * 是否可写
     * @return bool
     */
    public function isWritable(): bool
    {
        $meta = $this->getMetadata('mode');
        return $meta[0] === 'w' ||
               $meta[0] === 'a' ||
               $meta[0] === 'x' ||
               $meta[0] === 'c';
    }

    /**
     * 是否可读
     * @return bool
     */
    public function isReadable(): bool
    {
        $meta = $this->getMetadata('mode');
        return $meta[0] === 'r' || $meta[0] === 'r+';
    }

    /**
     * 获取元信息
     * @param ?string $key 元信息键
     * @return mixed 值或完整数组
     */
    public function getMetadata(?string $key = null): mixed
    {
        $meta = stream_get_meta_data($this->resource);
        return $key ? $meta[$key] : $meta;
    }

    /**
     * 转为字符串
     * @return string 内容
     * @throws ConnectionException
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * 读取全部内容
     * @return string 内容
     * @throws ConnectionException
     */
    public function getContents(): string
    {
        if ($this->closed || !is_resource($this->resource)) {
            throw new ConnectionException('No valid resource available');
        }

        $contents =  stream_get_contents($this->resource);

        if ($contents === false) {
            throw new ConnectionException('Unable to read stream contents');
        }

        return $contents;
    }
}
