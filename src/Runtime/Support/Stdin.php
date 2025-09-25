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

use function fwrite;

use const PHP_EOL;
use const STDOUT;

/**
 * @class Stdin Stdin helper class
 */
final class Stdin
{
    /**
     * @param string $message
     * @return void
     */
    public static function println(string $message): void
    {
        Stdin::print($message . PHP_EOL);
    }

    /**
     * @param string $message
     * @return void
     */
    public static function print(string $message): void
    {
        @fwrite(STDOUT, $message);
    }
}
