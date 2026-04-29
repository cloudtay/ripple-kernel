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
use Ripple\Runtime\Support\Stdin;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
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
     * @var Closure(Request):void
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
        public readonly Stream $stream,
        callable               $dispatcher,
        private array          $meta = []
    ) {
        $this->dispatcher = Closure::fromCallable($dispatcher);
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

        foreach ($this->parser->push($content) as $reqInfo) {
            $reqs[] = new Request(
                $reqInfo['get'],
                $reqInfo['post'],
                $reqInfo['cookies'],
                $reqInfo['files'],
                $reqInfo['server'],
                $reqInfo['content'],
                stream: $this->stream,
            );
        }

        return $reqs;
    }

    /**
     * 处理 HTTP 请求
     * @param Request $req 请求信息
     * @return void
     * @throws ConnectionException
     */
    private function onRequest(Request $req): void
    {
        $response = $req->response();
        $response->withHeader('Server', 'ripple');

        // 半关闭检测
        $connHeader = $req->SERVER['HTTP_CONNECTION'] ?? '';
        $upgradeHeader = $req->SERVER['HTTP_UPGRADE'] ?? '';
        $keepAlive = strtolower($connHeader) !== 'close';
        $isWebSocketUpgrade = strtolower($upgradeHeader) === 'websocket' && str_contains(strtolower($connHeader), 'upgrade');

        if ($keepAlive || $isWebSocketUpgrade) {
            $response->withHeader('Connection', $keepAlive ? 'keep-alive' : 'Upgrade');
        } else {
            $response->withHeader('Connection', 'close');
        }

        try {
            ($this->dispatcher)($req);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $req->respond($exception->getMessage(), [], 500);
        }
    }
}
