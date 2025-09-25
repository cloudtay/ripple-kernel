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

namespace Ripple\Lib\File;

use FFI;
use Ripple\Bridge;
use Ripple\Runtime;
use Throwable;

use function implode;
use function sprintf;

use const PHP_OS_FAMILY;

/**
 * 文件操作抽象类
 */
abstract class FileAbstract implements FileInterface
{
    /** 动态库名 */
    protected string $name = 'file';

    /** 事件桥 */
    protected Bridge $bridge;

    /** FFI句柄 */
    protected mixed $ffi;

    public function __construct()
    {
        $this->bridge = Runtime::bridge();
        $this->initFFI();
    }

    private function initFFI(): void
    {
        $ext = $this->detectExt();
        $libPath = sprintf('%s/../target/release/lib%s.%s', __DIR__, $this->name, $ext);
        $abi = implode("\n", $this->abi);
        $this->ffi = FFI::cdef($abi, $libPath);
        $this->ffi->link($this->bridge->fifo());
    }

    /**
     * 检测平台库后
     * @return string
     */
    private function detectExt(): string
    {
        $family = PHP_OS_FAMILY ?? '';
        if ($family === 'Darwin') {
            return 'dylib';
        }
        if ($family === 'Windows') {
            return 'dll';
        }

        return 'so';
    }

    /** ABI */
    protected array $abi = [
        'int link(const char* runtime_dir);',
        'char* file_get_contents(const char* path);',
        'char* file_get_contents_async(const char* path, uint64_t request_id);',
        'int process_file(int client_fd, const char* path);',
    ];

    /** 同步文件读取 */
    public function file_get_contents(string $path): string
    {
        $ptr = $this->ffi->file_get_contents($path);
        return FFI::string($ptr);
    }

    /**
     * @param string $path
     * @return string
     * @throws Throwable
     */
    public function file_get_contents_async(string $path): string
    {
        $requestId = $this->bridge->subCoroutine();
        $ptr = $this->ffi->file_get_contents_async($path, $requestId);
        $this->bridge->await($requestId);
        return FFI::string($ptr);
    }

    /**
     * 同步发送文件到指定fd
     * @param int $fd
     * @param string $path
     * @return int 0成功，-1失败
     */
    public function process_file(int $fd, string $path): int
    {
        return (int)$this->ffi->process_file($fd, $path);
    }
}
