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

namespace Ripple\Net\WebSocket\Server;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\Protocol\ResponseEmitter;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Server as HttpServer;
use Ripple\Net\Http\Request;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function base64_encode;
use function sha1;
use function str_contains;
use function strtolower;
use function Co\go;
use function sprintf;

class Server
{
    /**
     * 连接处理器
     * @var Closure|null
     */
    public ?Closure $onConnect = null;

    /**
     * 请求处理器
     * @var Closure|null
     */
    public ?Closure $onRequest = null;

    /**
     * HTTP 服务器实例
     * @var HttpServer
     */
    private HttpServer $httpServer;

    /**
     * 构造函数
     * @param string $address 监听地址
     * @param mixed|null $streamContext 流上下文选项
     * @throws ConnectionException
     */
    public function __construct(string $address, mixed $streamContext = null)
    {
        $this->httpServer = new HttpServer($address, $streamContext);
        $this->setupRequestHandler();
    }

    /**
     * 设置请求处理器
     * @return void
     */
    private function setupRequestHandler(): void
    {
        $this->httpServer->onRequest = fn (Request $request) => $this->handleWebSocketRequest($request);
    }

    /**
     * 处理 WebSocket 请求
     * @param Request $request
     * @return ResponseInterface|null
     * @throws ConnectionException
     */
    private function handleWebSocketRequest(Request $request): ?ResponseInterface
    {
        if (!$this->isWebSocketUpgrade($request)) {
            return Response::text('Not Found', 404);
        }

        if (!$this->performHandshake($request)) {
            return Response::text('Bad Request', 400);
        }

        $connection = new Connection($request->stream(), $request);

        if (isset($this->onRequest)) {
            try {
                ($this->onRequest)($request, $connection);
            } catch (Throwable $e) {
                return Response::text(sprintf("%s:%s", 'Bad Request', $e->getMessage()), 400);
            }
        }

        if (!$connection->isAlive()) {
            return null;
        }

        if (isset($this->onConnect)) {
            $handler = $this->onConnect;
            go(static fn () => $handler($connection));
        }

        return null;
    }

    /**
     * 检查是否为 WebSocket 升级请求
     * @param Request $request
     * @return bool
     */
    private function isWebSocketUpgrade(Request $request): bool
    {
        $headers = $request->SERVER;

        $upgrade = $headers['HTTP_UPGRADE'] ?? $headers['UPGRADE'] ?? '';
        $connection = $headers['HTTP_CONNECTION'] ?? $headers['CONNECTION'] ?? '';
        $key = $headers['HTTP_SEC_WEBSOCKET_KEY'] ?? $headers['SEC_WEBSOCKET_KEY'] ?? '';
        $version = $headers['HTTP_SEC_WEBSOCKET_VERSION'] ?? $headers['SEC_WEBSOCKET_VERSION'] ?? '';

        return strtolower($upgrade) === 'websocket' &&
                str_contains(strtolower($connection), 'upgrade') &&
                $key !== '' &&
                $version === '13';
    }

    /**
     * 执行 WebSocket 握手
     * @param Request $request
     * @return bool
     */
    private function performHandshake(Request $request): bool
    {
        $headers = $request->SERVER;
        $key = $headers['HTTP_SEC_WEBSOCKET_KEY'] ?? $headers['SEC_WEBSOCKET_KEY'] ?? '';
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = (new Response(101))
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Connection', 'Upgrade')
            ->withHeader('Sec-WebSocket-Accept', $accept);

        try {
            (new ResponseEmitter())->emit($response, $request->stream());
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 启动服务器
     * @return void
     */
    public function listen(): void
    {
        $this->httpServer->listen();
    }

    /**
     * 停止服务器
     * @return void
     */
    public function stop(): void
    {
        $this->httpServer->stop();
    }
}
