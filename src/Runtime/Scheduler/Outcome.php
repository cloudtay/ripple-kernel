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

namespace Ripple\Runtime\Scheduler;

use Ripple\Coroutine;
use Ripple\Runtime\Scheduler;
use Throwable;

use function debug_backtrace;

class Outcome
{
    /**
     * @var bool
     */
    private bool $resolved = false;

    /**
     * @var array
     */
    private array $trace = [];

    /**
     * @param string $action
     * @param mixed $value
     * @param ?Coroutine $coroutine
     * @param Throwable|null $exception
     */
    public function __construct(
        private readonly string     $action,
        private readonly mixed      $value,
        private readonly ?Coroutine $coroutine,
        private readonly ?Throwable $exception = null
    ) {
        if ($exception) {
            Scheduler::auditFailure($this);
            $this->trace = debug_backtrace();
        }
    }

    /**
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->exception === null;
    }

    /**
     * @return bool
     */
    public function isError(): bool
    {
        return $this->exception !== null;
    }

    /**
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * @return string
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * @return mixed
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @return Throwable|null
     */
    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * @return array
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * @return ?Coroutine
     */
    public function coroutine(): ?Coroutine
    {
        return $this->coroutine;
    }

    /**
     * @param string|null $type
     * @return mixed
     */
    public function unwrap(?string $type = null): mixed
    {
        if ($this->exception) {
            if (!$type || $this->exception instanceof $type) {
                $this->resolved = true;
            }
            return $this->exception;
        }

        return $this->value;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function rethrow(): void
    {
        if ($this->exception) {
            throw $this->unwrap();
        }
    }

    /**
     * @param string $action
     * @param mixed $value
     * @param Coroutine|null $coro
     * @return static
     */
    public static function success(string $action, mixed $value, ?Coroutine $coro = null): static
    {
        return new static($action, $value, $coro, null);
    }

    /**
     * @param string $action
     * @param Throwable $e
     * @param Coroutine|null $coro
     * @return static
     */
    public static function failure(string $action, Throwable $e, ?Coroutine $coro = null): static
    {
        return new static($action, null, $coro, $e);
    }
}
