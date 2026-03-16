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

namespace Ripple\Net\Http\Server\Upload;

use Ripple\Net\Exception\FormatException;
use Ripple\Runtime\Exception\RuntimeException;

use function fclose;
use function fopen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function preg_match;
use function sprintf;
use function fwrite;
use function var_dump;

/**
 * Http upload parser
 */
class Multipart
{
    private const STATUS_WAITING_META = 0;

    private const STATUS_TRAN = 1;

    /**
     * @var int
     */
    private int $status = Multipart::STATUS_WAITING_META;

    /**
     * @var array|null
     */
    private array|null $filling = null;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * 上传文件
     * @param string $boundary
     */
    public function __construct(private readonly string $boundary)
    {
    }

    /**
     * CONTEXT PUSH
     * @param string $content
     * @return array
     * @throws FormatException
     */
    public function fill(string $content): array
    {
        $this->buffer .= $content;
        $files       = array();

        do {
            if ($this->status === Multipart::STATUS_WAITING_META) {
                if ($meta = $this->parseMeta()) {
                    $meta['path'] = sprintf('%s/%s/%s', sys_get_temp_dir(), '/', uniqid());
                    $meta['stream'] = fopen($meta['path'], 'wb+');
                    $this->filling = $meta;

                    $this->status = Multipart::STATUS_TRAN;
                }
            }

            if ($this->status === Multipart::STATUS_TRAN) {
                $meta = $this->filling;

                $buffer = $this->readBuffer();
                $boundaryPosition = strpos($buffer, "--{$this->boundary}");
                if ($boundaryPosition !== false) {
                    $buffer = substr($buffer, 0, $boundaryPosition - 2);
                    $this->buffer = substr($buffer, $boundaryPosition);
                }

                // 填充文件
                fwrite($meta['stream'], $buffer);

                if ($boundaryPosition !== false) {
                    // 释放文件
                    fclose($meta['stream']);
                    unset($meta['stream']);
                    $files[$meta['name']][] = $meta;
                    $this->filling = null;

                    $this->status = Multipart::STATUS_WAITING_META;
                    continue;
                }
            }

            break;
        } while (1);

        return $files;
    }

    /**
     * 解析文件元信息
     * @return array|false
     * @throws FormatException
     */
    private function parseMeta(): array|false
    {
        $headerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }

        $header       = substr($this->buffer, 0, $headerEnd);
        $re = '/^Content-Disposition:\x20*form-data\x20*;\x20*name="([^"]+)"\x20*;?\x20*(?:filename="([^"]*)")?\x20*(?:\r?\nContent-Type:\x20*(.+))?\r?\n/umi';
        if (!preg_match($re, $header, $matches)) {
            var_dump($header);
            throw new RuntimeException('not match meta information');
        }

        $this->buffer = substr($this->buffer, $headerEnd + 4);
        return array(
            'name'        => $matches[1] ?? null,
            'fileName'    => $matches[2] ?? null,
            'contentType' => $matches[3] ?? null,
        );
    }

    /**
     * 读取缓冲区数据
     * @param int $length 读取长度
     * @return string 读取的数据
     */
    private function readBuffer(int $length = 0): string
    {
        if ($length === 0) {
            $buffer   = $this->buffer;
            $this->buffer = '';
            return $buffer;
        }

        $buffer = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $buffer;
    }
}
