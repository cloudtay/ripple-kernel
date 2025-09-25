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

use Ripple\Process;
use Ripple\Time;

require_once __DIR__ . '/../vendor/autoload.php';


Process::forked(function () {
    echo 'sub forked event' . \PHP_EOL;
});

$child = Process::fork(function () {
    \var_dump('is child');
    Time::sleep(10);
    exit(127);
});

\var_dump(Process::wait($child));
