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
use InvalidArgumentException;
use Ripple\Coroutine;
use Ripple\Event;
use Ripple\Net\Http\Server\Connection;
use Ripple\Runtime\HotCoroutinePool;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use UnexpectedValueException;

use function Co\suspend;
use function is_resource;
use function parse_url;
use function stream_socket_server;
use function stream_set_blocking;
use function stream_socket_accept;
use function fclose;
use function socket_import_stream;
use function socket_setopt;
use function call_user_func;

use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const SOL_TCP;
use const TCP_NODELAY;

/**
 * Http service class
 */
class Server
{
    /**
     * request handler
     * @var Closure
     */
    public Closure $onRequest;

    /**
     * @var HotCoroutinePool
     */
    private HotCoroutinePool $hotCoroutinePool;

    /**
     * @var resource
     */
    private mixed $server;

    /**
     * @var int|null
     */
    private ?int $watchId = null;

    /**
     * @param string     $address
     * @param mixed|null $streamContext
     * @throws ConnectionException
     */
    public function __construct(string $address, mixed $streamContext = null)
    {
        $addressInfo = parse_url($address);

        if (!$scheme = $addressInfo['scheme'] ?? null) {
            throw new InvalidArgumentException('Address format error');
        }

        if (!$host = $addressInfo['host']) {
            throw new InvalidArgumentException('Address format error');
        }

        $port = $addressInfo['port'] ?? match ($scheme) {
            'http'  => 80,
            'https' => 443,
            default => throw new InvalidArgumentException('Address format error')
        };

        $server = stream_socket_server(
            "tcp://{$host}:{$port}",
            $errNo,
            $errMsg,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $streamContext
        );

        if ($server === false || !is_resource($server)) {
            throw new ConnectionException('Failed to create server');
        }

        $this->server = $server;
        stream_set_blocking($this->server, false);
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        if (!isset($this->onRequest)) {
            throw new UnexpectedValueException('Request handler callback is not set. Please set onRequest property before calling listen()');
        }

        $this->hotCoroutinePool = new HotCoroutinePool(function () {
            call_user_func($this->onRequest, suspend());
        }, 200);

        $this->watchId = Event::watchRead($this->server, function () {
            $client = @stream_socket_accept($this->server, 0, $remoteAddr);
            if (!$client) {
                return;
            }

            $socket = socket_import_stream($client);
            socket_setopt($socket, SOL_TCP, TCP_NODELAY, 1);
            //            @socket_setopt($socket, SOL_SOCKET, SO_RCVBUF, 65536);
            //            @socket_setopt($socket, SOL_SOCKET, SO_SNDBUF, 65536);

            $remoteInfo = parse_url("tcp://{$remoteAddr}");

            $stream = new Stream($client);
            $connection = new Connection($stream, [
                'REMOTE_ADDR' => $remoteInfo['host'] ?? '',
                'REMOTE_PORT' => (int)($remoteInfo['port'] ?? 0),
            ]);

            $connection->start($this);
        });
    }

    /**
     * 停止服务器
     * @return void
     */
    public function stop(): void
    {
        if ($this->watchId !== null) {
            Event::unwatch($this->watchId);
            $this->watchId = null;
        }

        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    /**
     * @return HotCoroutinePool
     */
    public function hotCoroutinePool(): HotCoroutinePool
    {
        return $this->hotCoroutinePool;
    }

    /**
     * 获取热协程
     * @return Coroutine
     */
    public function acquireCoroutine(): Coroutine
    {
        return $this->hotCoroutinePool->acquire();
    }
}
