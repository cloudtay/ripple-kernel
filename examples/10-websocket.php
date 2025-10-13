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

use Ripple\Net\WebSocket;
use Ripple\Net\WebSocket\Server\Connection;

use function Co\wait;

require_once __DIR__ . '/../vendor/autoload.php';

// 创建 WebSocket 服务器
$server = WebSocket::server('ws://0.0.0.0:8001');

if ($server instanceof Throwable) {
    echo "Failed to create WebSocket server: " . $server->getMessage() . \PHP_EOL;
    exit(1);
}

echo "WebSocket server started on ws://0.0.0.0:8001" . \PHP_EOL;

$server->onRequest = function (mixed $request) {
    \var_dump('on request');
};

$server->onConnect = function (Connection $connection) {
    echo "New WebSocket connection established" . \PHP_EOL;

    // 设置消息处理器
    $connection->onMessage = function (string $message, Connection $conn) {
        echo "Received message: " . $message . \PHP_EOL;
        $conn->sendText("Echo: " . $message);
    };

    // 设置关闭处理器
    $connection->onClose = function (Connection $conn) {
        echo "WebSocket connection closed" . \PHP_EOL;
    };

    $connection->sendText("Welcome to WebSocket server!");
};

// 启动服务器
$server->listen();
wait();
