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

use function array_pop;
use function array_shift;
use function Co\defer;
use function explode;
use function fclose;
use function file_exists;
use function fopen;
use function fwrite;
use function preg_match;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

/**
 * Http upload parser
 */
class Multipart
{
    private const STATUS_WAIT = 0;
    private const STATUS_TRAN = 1;

    /**
     * @var int
     */
    private int $status = Multipart::STATUS_WAIT;

    /**
     * @var array
     */
    private array $filling;

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
        $result       = array();
        while (!empty($this->buffer)) {
            if ($this->status === Multipart::STATUS_WAIT) {
                if (!$info = $this->parseFileInfo()) {
                    break;
                }

                $this->status = Multipart::STATUS_TRAN;

                if (!empty($info['fileName'])) {
                    $info['path']   = sys_get_temp_dir() . '/' . uniqid();
                    $info['stream'] = fopen($info['path'], 'wb+');
                    $this->filling  = $info;

                    $tmpPath = $info['path'];
                    defer(static fn () => file_exists($tmpPath) && @unlink($tmpPath));
                } else {
                    $this->status = Multipart::STATUS_WAIT;
                    $textContent  = $this->parseChunk();
                    if ($textContent !== false) {
                        $result[$info['name']] = $textContent;
                    }
                }
            }

            if ($this->status === Multipart::STATUS_TRAN) {
                if (!$this->fillFile()) {
                    break;
                }
                $this->status                  = Multipart::STATUS_WAIT;
                $result[$this->filling['name']][] = [
                    'path' => $this->filling['path'],
                    'fileName' => $this->filling['fileName'],
                    'contentType' => $this->filling['contentType'],
                ];
                fclose($this->filling['stream']);
            }
        }

        return $result;
    }

    /**
     * 持续填充
     * @return bool
     */
    private function fillFile(): bool
    {
        $mode = "\r\n--{$this->boundary}\r\n";

        $fileContent      = $this->buffer;
        $boundaryPosition = strpos($fileContent, $mode);

        if ($boundaryPosition === false) {
            $boundaryPosition = strpos($fileContent, "\r\n--{$this->boundary}--");
        }

        if ($boundaryPosition !== false) {
            $fileContent  = substr($fileContent, 0, $boundaryPosition);
            $this->buffer = substr($this->buffer, $boundaryPosition + 2);
            fwrite($this->filling['stream'], $fileContent);
            return true;
        } else {
            $this->buffer = '';
            fwrite($this->filling['stream'], $fileContent);
            return false;
        }
    }

    /**
     * 解析文件元信息
     * @return array|false
     * @throws FormatException
     */
    private function parseFileInfo(): array|false
    {
        $headerEndPosition = strpos($this->buffer, "\r\n\r\n");
        if ($headerEndPosition === false) {
            return false;
        }

        $header       = substr($this->buffer, 0, $headerEndPosition);
        $this->buffer = substr($this->buffer, $headerEndPosition + 4);

        $headerLines = explode("\r\n", $header);

        $boundaryLine = array_shift($headerLines);
        if (trim($boundaryLine) !== '--' . $this->boundary) {
            throw new FormatException('Boundary is invalid');
        }

        $name        = '';
        $fileName    = '';
        $contentType = '';

        while ($line = array_pop($headerLines)) {
            if (preg_match('/^Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?$/i', trim($line), $matches)) {
                $name = $matches[1];
                if (isset($matches[2])) {
                    $fileName = $matches[2];
                }
            } elseif (preg_match('/^Content-Type:\s*(.+)$/i', trim($line), $matches)) {
                $contentType = $matches[1];
            }
        }

        if ($name === '') {
            throw new FormatException('File information is incomplete');
        }

        if ($contentType && $contentType !== 'text/plain' && $fileName === '') {
            throw new FormatException('Content type must be text/plain for non-file fields');
        }

        return array(
            'name'        => $name,
            'fileName'    => $fileName,
            'contentType' => $contentType
        );
    }

    /**
     * 解析文本内容
     * @return string|false
     */
    private function parseChunk(): string|false
    {
        $boundaryPosition = strpos($this->buffer, "\r\n--{$this->boundary}");
        if ($boundaryPosition === false) {
            return false;
        }

        $textContent  = substr($this->buffer, 0, $boundaryPosition);
        $this->buffer = substr($this->buffer, $boundaryPosition + 2);
        return $textContent;
    }

    /**
     * 取消填充流程
     * @return void
     */
    public function cancel(): void
    {
        if ($this->status === Multipart::STATUS_TRAN) {
            fclose($this->filling['stream']);
        }
    }
}
