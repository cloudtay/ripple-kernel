<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Event;
use Ripple\Time;

use function Co\go;
use function Co\wait;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function stream_set_blocking;
use function stream_socket_pair;
use function stream_socket_shutdown;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SHUT_WR;
use const STREAM_SOCK_STREAM;

final class StreamWatcherEofTest extends TestCase
{
    /**
     * @testdox EOF 前后读回调应区分且可按阈值取消
     */
    public function testWatcherReadCallbacksAroundEof(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $beforeEofCount = 0;
        $afterEofCount  = 0;
        $watchId = null;

        // 服务端：少量写入后半关闭写端
        go(function () use ($server) {
            Time::sleep(0.2);
            fwrite($server, "ping\n");
            Time::sleep(0.2);
            @stream_socket_shutdown($server, STREAM_SHUT_WR);
        });

        // 客户端：注册读事件, 统计 EOF 前后
        go(function () use ($client, &$watchId, &$beforeEofCount, &$afterEofCount) {
            $watchId = Event::watchRead($client, function (int $id, $stream) use (&$beforeEofCount, &$afterEofCount) {
                $data = @fread($stream, 8192) ?: '';
                $isEof = feof($stream);

                if ($data !== '') {
                    $beforeEofCount++;
                    return;
                }

                if ($isEof) {
                    $afterEofCount++;
                    if ($afterEofCount >= 3) {
                        Event::unwatch($id);
                    }
                    return;
                }
            });
        });

        // 等待并清理
        go(function () use ($client, $server) {
            Time::sleep(1.0);
            @fclose($client);
            @fclose($server);
        });

        wait();

        $this->assertGreaterThanOrEqual(1, $beforeEofCount);
        $this->assertGreaterThanOrEqual(1, $afterEofCount);
    }
}
