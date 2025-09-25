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

namespace Co;

use Closure;
use Ripple\Runtime;
use Ripple\Coroutine;
use Ripple\Time;
use RuntimeException;
use Throwable;

/**
 * @param Closure $callback
 * @return Coroutine
 */
function go(Closure $callback): Coroutine
{
    return Coroutine::go($callback);
}

/**
 * @return Coroutine
 */
function current(): Coroutine
{
    return Coroutine::current();
}

/**
 * @param float $seconds
 * @return void
 */
function sleep(float $seconds): void
{
    Time::sleep($seconds);
}

/**
 * @param Closure $callback
 * @return void
 */
function defer(Closure $callback): void
{
    current()->defer($callback);
}

/**
 * @return mixed
 */
function suspend(): mixed
{
    try {
        return current()->suspend();
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
    }
}

/**
 * @return void
 */
function wait(): void
{
    try {
        Runtime::main()->suspend();
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
    }
}
