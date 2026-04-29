<?php declare(strict_types=1);

namespace Ripple\Net\Http\Exception;

use Psr\Http\Message\RequestInterface;
use Throwable;

final class TimeoutException extends NetworkException
{
    public function __construct(
        string $message,
        RequestInterface $request,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $request, $code, $previous);
    }
}
