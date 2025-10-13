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

namespace Ripple\Net;

use Ripple\Net\WebSocket\Server\Server;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;
use RuntimeException;

use function str_replace;

class WebSocket
{
    /**
     * 创建 WebSocket 服务器
     * @param string $address 监听地址, 格式：ws://host:port 或 wss://host:port
     * @param mixed|null $streamContext 流上下文选项
     * @return Server|Throwable
     */
    public static function server(string $address, mixed $streamContext = null): Server|Throwable
    {
        try {
            $httpAddress = self::convertToHttpAddress($address);
            return new Server($httpAddress, $streamContext);
        } catch (ConnectionException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string $wsAddress
     * @return string
     */
    private static function convertToHttpAddress(string $wsAddress): string
    {
        $wsAddress = str_replace('ws://', 'http://', $wsAddress);
        $wsAddress = str_replace('wss://', 'https://', $wsAddress);
        return $wsAddress;
    }
}
