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

/**
 * 文件操作接口
 */
interface FileInterface
{
    /**
     * 异步读取文件
     * @param string $path 文件路径
     * @return string 文件内容
     */
    public function file_get_contents_async(string $path): string;

    /**
     * 同步读取文件
     * @param string $path 文件路径
     * @return string 文件内容
     */
    public function file_get_contents(string $path): string;

    /**
     * 发送文件到指定fd
     * @param int $fd 目标socket文件描述符
     * @param string $path 文件路径
     * @return int 0成功，-1失败
     */
    public function process_file(int $fd, string $path): int;
}
