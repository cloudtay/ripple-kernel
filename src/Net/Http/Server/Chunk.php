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

namespace Ripple\Net\Http\Server;

use function dechex;
use function explode;
use function strlen;
use function is_int;

/**
 *
 */
class Chunk
{
    /**
     * @Author cclilshy
     * @Date   2024/9/1 15:51
     * @param array $fields
     * @return string
     */
    public static function event(array $fields = []): string
    {
        $output = "";

        foreach ($fields as $key => $value) {
            $valueString = (string)$value;
            $lines       = explode("\n", $valueString);

            if (is_int($key)) {
                foreach ($lines as $line) {
                    $output .= "data: {$line}\n";
                }
                continue;
            }

            foreach ($lines as $line) {
                $output .= "{$key}: {$line}\n";
            }
        }

        $output .= "\n";
        return $output;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 15:51
     * @param string $data
     * @return string
     */
    public static function chunk(string $data): string
    {
        $length = dechex(strlen($data));
        return "{$length}\r\n{$data}\r\n";
    }
}
