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

namespace Ripple\Net\Http;

use Closure;
use Ripple\Net\Http\Parser\RequestParser;
use Ripple\Net\Http\Protocol\ResponseEmitter;
use Ripple\Runtime\Support\Stdin;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function str_contains;
use function strtolower;

/**
 * HTTP连接处理器
 */
class Connection
{
    private RequestParser $parser;

    /**
     * @var Closure(Request):ResponseInterface
     */
    private readonly Closure $dispatcher;

    /**
     * @var array
     */
    private readonly array $alwaysMeta;

    /**
     * 构造函数
     * @param Stream $stream 流对象
     * @param callable(Request):void $dispatcher
     * @param array $meta 服务器信息
     */
    public function __construct(
        public readonly Stream           $stream,
        callable                         $dispatcher,
        private readonly array           $meta = [],
        private readonly ResponseEmitter $emitter = new ResponseEmitter(),
    ) {
        $this->dispatcher = $dispatcher(...);
        $this->alwaysMeta = $this->meta;
        $this->parser = new RequestParser($this->alwaysMeta);
    }

    /**
     * 启动连接处理
     * @return void
     */
    public function start(): void
    {
        try {
            $this->stream->watchRead(function () {
                try {
                    $content = $this->stream->read(8192);
                    if ($content === '' && $this->stream->eof()) {
                        throw new ConnectionException();
                    }

                    foreach ($this->fill($content) as $req) {
                        $this->onRequest($req);
                    }
                } catch (Throwable $e) {
                    if (!$e instanceof ConnectionException) {
                        Stdin::println($e->getMessage());
                    }

                    $this->stream->close();
                }
            });
        } catch (ConnectionException) {
            $this->stream->close();
        }
    }

    /**
     * 处理接收到的数据
     * @param string $content 接收的数据
     * @return Request[] 解析出的请求列表
     */
    private function fill(string $content): array
    {
        $reqs = [];

        foreach ($this->parser->push($content) as $request) {
            $reqs[] = $request->withStream($this->stream);
        }

        return $reqs;
    }

    /**
     * 处理 HTTP 请求
     * @param Request $req 请求信息
     * @return ResponseInterface|null
     * @throws ConnectionException
     */
    private function onRequest(Request $req): ?ResponseInterface
    {
        $responder = new ServerResponder();
        $req->bindResponder($responder);

        try {
            $response = ($this->dispatcher)($req);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $response = Response::text($exception->getMessage(), 500);
        }

        $response = $response instanceof ResponseInterface ? $response : $responder->response();
        if (!$response instanceof ResponseInterface) {
            return null;
        }

        // 半关闭检测
        $connHeader = $req->SERVER['HTTP_CONNECTION'] ?? '';
        $upgradeHeader = $req->SERVER['HTTP_UPGRADE'] ?? '';
        $keepAlive = strtolower($connHeader) !== 'close';
        $isWebSocketUpgrade = strtolower($upgradeHeader) === 'websocket' && str_contains(strtolower($connHeader), 'upgrade');

        if ($keepAlive || $isWebSocketUpgrade) {
            $response = $response->withHeader('Connection', $keepAlive ? 'keep-alive' : 'Upgrade');
        } else {
            $response = $response->withHeader('Connection', 'close');
        }

        $response = $response->withHeader('Server', 'ripple');
        $this->emitter->emit($response, $this->stream);
        return $response;
    }

    /**
     * @param string $content
     * @return list<ResponseInterface>
     * @throws ConnectionException
     */
    public function fillForTest(string $content): array
    {
        $responses = [];
        foreach ($this->fill($content) as $request) {
            $response = $this->onRequest($request);
            if ($response instanceof ResponseInterface) {
                $responses[] = $response;
            }
        }

        return $responses;
    }
}
