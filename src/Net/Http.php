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

use Ripple\Net\Http\Client;
use Ripple\Net\Http\Server;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;
use RuntimeException;

class Http
{
    /**
     * 创建 HTTP 服务器
     * @param string $address 监听地址, 格式：http://host:port 或 https://host:port
     * @param mixed|null $steamContext 流上下文选项
     * @return Server|Throwable
     */
    public static function server(string $address, mixed $steamContext = null): Server|Throwable
    {
        try {
            return new Server($address, $steamContext);
        } catch (ConnectionException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 创建 HTTP 客户端
     * @param array $config 客户端配置选项
     * @return Client
     */
    public static function client(array $config = []): Client
    {
        return new Client($config);
    }
}
