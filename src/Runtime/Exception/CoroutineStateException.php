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

namespace Ripple\Runtime\Exception;

use Ripple\Coroutine;
use Throwable;

/**
 * 协程状态异常
 */
class CoroutineStateException extends CoroutineException
{
    public const CODE_INVALID_STATE = 1001;
    public const CODE_ALREADY_RUNNING = 1002;
    public const CODE_ALREADY_TERMINATED = 1003;
    public const CODE_NOT_RUNNABLE = 1004;

    /**
     * @param string $message
     * @param ?Coroutine $coroutine
     * @param ?Throwable $previous
     * @param array $context 错误上下文
     */
    public function __construct(
        string     $message = "",
        ?Coroutine $coroutine = null,
        ?Throwable $previous = null,
        array      $context = []
    ) {
        parent::__construct($message, $coroutine, $previous, $context);
        $this->context = $context;
    }

    /**
     * 创建无效状态异常
     * @param string $message
     * @param string $currentState 当前状态
     * @param string $expectedState 期望状态
     * @param ?Coroutine $coroutine 协程对象
     * @return static
     */
    public static function invalidState(
        string     $currentState,
        string     $expectedState,
        ?Coroutine $coroutine = null,
        string     $message = "",
    ): static {
        $context = [];
        if ($coroutine !== null) {
            $context['coroutine'] = $coroutine;
        }

        return new static(
            "{$message}, Coroutine state error: expected {$expectedState}, got {$currentState}",
            $coroutine,
            null,
            $context
        );
    }

    /**
     * 创建不可运行异常
     * @param string $currentState 当前状态
     * @param ?Coroutine $coroutine 协程对象
     * @return static
     */
    public static function notRunnable(
        string $currentState,
        ?Coroutine $coroutine = null
    ): static {
        $context = [];
        if ($coroutine !== null) {
            $context['coroutine'] = $coroutine;
        }

        return new static(
            "Coroutine is not runnable, current state: {$currentState}",
            $coroutine,
            null,
            $context
        );
    }
}
