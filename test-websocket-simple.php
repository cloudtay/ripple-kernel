<?php declare(strict_types=1);

use Ripple\Net\WebSocket;
use Ripple\Net\WebSocket\Server\Connection;

use function Co\wait;

require_once __DIR__ . '/vendor/autoload.php';

// 创建 WebSocket 服务器
$server = WebSocket::server('ws://0.0.0.0:8002');

if ($server instanceof Throwable) {
    echo "Failed to create WebSocket server: " . $server->getMessage() . \PHP_EOL;
    exit(1);
}

echo "WebSocket server started on ws://0.0.0.0:8002" . \PHP_EOL;

$server->onRequest = function (mixed $request) {
    echo "onRequest called: " . \get_class($request) . \PHP_EOL;
};

// 设置连接处理器
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

    // 发送欢迎消息
    $connection->sendText("Welcome to WebSocket server!");
};

// 启动服务器
$server->listen();

// 保持运行
wait();
