<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function get_resource_type;
use function is_resource;
use function rewind;
use function stream_get_contents;
use function stream_get_meta_data;
use function strpbrk;

use const SEEK_SET;

/**
 * PSR HTTP消息体流
 */
final class BodyStream implements StreamInterface
{
    /**
     * 底层PHP流资源
     * @var resource|null
     */
    private mixed $resource;

    /**
     * 从字符串创建消息体流
     * @param string $content
     * @return self
     */
    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+');
        if (!is_resource($resource)) {
            throw new RuntimeException('Unable to create temporary body stream.');
        }

        fwrite($resource, $content);
        rewind($resource);
        return new self($resource);
    }

    /**
     * 创建消息体流
     * @param resource $resource
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource)) {
            throw new RuntimeException('BodyStream expects a PHP stream resource.');
        }

        $this->resource = $resource;
    }

    /**
     * 将流内容转换为字符串
     * @return string
     */
    public function __toString(): string
    {
        if (!$this->resource) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * 关闭底层流
     * @return void
     */
    public function close(): void
    {
        if ($this->resource) {
            fclose($this->resource);
        }

        $this->resource = null;
    }

    /**
     * 分离底层流资源
     * @return resource|null
     */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    /**
     * 获取流大小
     * @return int|null
     */
    public function getSize(): ?int
    {
        if (!$this->resource) {
            return null;
        }

        $stats = fstat($this->resource);
        return $stats['size'] ?? null;
    }

    /**
     * 获取当前读写位置
     * @return int
     */
    public function tell(): int
    {
        $this->assertAttached();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    /**
     * 判断是否到达流末尾
     * @return bool
     */
    public function eof(): bool
    {
        return !$this->resource || feof($this->resource);
    }

    /**
     * 判断流是否可定位
     * @return bool
     */
    public function isSeekable(): bool
    {
        return $this->resource && (($this->getMetadata('seekable') ?? false) === true);
    }

    /**
     * 移动流指针
     * @param int $offset 偏移量
     * @param int $whence 定位方式
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();
        if (!$this->isSeekable() || fseek($this->resource, $offset, $whence) !== 0) {
            throw new RuntimeException('Unable to seek stream.');
        }
    }

    /**
     * 重置流指针到开头
     * @return void
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * 判断流是否可写
     * @return bool
     */
    public function isWritable(): bool
    {
        $mode = (string)($this->getMetadata('mode') ?? '');
        return $this->resource && strpbrk($mode, 'waxc+') !== false;
    }

    /**
     * 写入流数据
     * @param string $string 写入内容
     * @return int 写入字节数
     */
    public function write(string $string): int
    {
        $this->assertAttached();
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream is not writable.');
        }

        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new RuntimeException('Unable to write stream.');
        }

        return $written;
    }

    /**
     * 判断流是否可读
     * @return bool
     */
    public function isReadable(): bool
    {
        $mode = (string)($this->getMetadata('mode') ?? '');
        return $this->resource && strpbrk($mode, 'r+') !== false;
    }

    /**
     * 读取指定长度数据
     * @param int $length 读取长度
     * @return string 读取内容
     */
    public function read(int $length): string
    {
        $this->assertAttached();
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable.');
        }

        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException('Unable to read stream.');
        }

        return $data;
    }

    /**
     * 读取剩余内容
     * @return string 剩余内容
     */
    public function getContents(): string
    {
        $this->assertAttached();
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }

        return $contents;
    }

    /**
     * 获取流元数据
     * @param string|null $key 元数据键名
     * @return mixed
     */
    public function getMetadata(?string $key = null): mixed
    {
        if (!$this->resource) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);
        if ($key === null) {
            $metadata['resource_type'] = get_resource_type($this->resource);
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * 确认流资源仍然可用
     * @return void
     */
    private function assertAttached(): void
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}
