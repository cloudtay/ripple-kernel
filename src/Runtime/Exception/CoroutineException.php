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
use RuntimeException;

/**
 * 基础异常类
 */
class CoroutineException extends RuntimeException
{
    /**
     * @var array{ callerTrace : ?array }
     */
    public array $context = [];

    /**
     * @var Coroutine|null
     */
    private ?Coroutine $coroutine;

    /**
     * @param string $message
     * @param ?Coroutine $coroutine
     * @param ?Throwable $previous
     * @param array $context
     */
    public function __construct(string $message, ?Coroutine $coroutine = null, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
        $this->coroutine = $coroutine;
    }

    /**
     * @return ?array
     */
    public function callerTrace(): ?array
    {
        return $this->context['callerTrace'] ?? null;
    }

    /**
     * @return Coroutine|null
     */
    public function coroutine(): ?Coroutine
    {
        return $this->coroutine;
    }
}
