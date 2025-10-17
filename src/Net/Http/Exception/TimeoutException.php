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

namespace Ripple\Net\Http\Exception;

use Ripple\Stream\Exception\ConnectionException;

use function sprintf;

/**
 * HTTP请求超时异常
 */
class TimeoutException extends ConnectionException
{
    /**
     * @param string $message 错误消息
     * @param float $timeout 超时时间
     * @param string $phase 超时阶段
     */
    public function __construct(string $message = 'Request timeout', float $timeout = 0, string $phase = 'request')
    {
        $fullMessage = sprintf('%s after %.3fs in %s phase', $message, $timeout, $phase);
        parent::__construct($fullMessage);
    }
}
