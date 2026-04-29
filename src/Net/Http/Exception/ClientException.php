<?php declare(strict_types=1);

namespace Ripple\Net\Http\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

class ClientException extends RuntimeException implements ClientExceptionInterface
{
}
