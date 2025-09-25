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

use function str_contains;

class FileTypeDetector
{
    /**
     * @var array
     */
    private static array $fileTypeCache = [];

    /**
     * 推断文件类型
     * @param string $file 文件路径
     * @return string 文件类型
     */
    public static function infer(string $file): string
    {
        if ($result = FileTypeDetector::$fileTypeCache[$file] ?? null) {
            return $result;
        }

        if (empty($file) || $file === 'unknown') {
            return FileTypeDetector::$fileTypeCache[$file] = 'unknown';
        }

        if (str_contains($file, __RIPPLE_RUNTIME_PKG_PATH)) {
            return FileTypeDetector::$fileTypeCache[$file] = 'runtime';
        }

        if (str_contains($file, __RIPPLE_VENDOR_PATH)) {
            return FileTypeDetector::$fileTypeCache[$file] = 'vendor';
        }

        return FileTypeDetector::$fileTypeCache[$file] = 'user';
    }
}
