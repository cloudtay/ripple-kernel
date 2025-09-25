<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Stream;
use Ripple\Time;
use Throwable;

use function Co\go;
use function Co\wait;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class StreamHalfReadThenWriteTest extends TestCase
{
    /**
     * @testdox 自身关闭读后仍可写且对端可读
     */
    public function testSelfShutdownReadThenWrite(): void
    {
        [$srvA, $cliA] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($srvA, false);
        stream_set_blocking($cliA, false);

        $server = new Stream($srvA);
        $client = new Stream($cliA);

        $read = '';

        go(function () use ($server) {
            $server->shutdownRead();
            $server->writeAll("A-hello\n");
        });

        go(function () use ($client, &$read) {
            $read = $client->read(1024);
            $client->close();
        });

        wait();

        $this->assertSame("A-hello\n", $read);
    }

    /**
     * @testdox 对端关闭读后本端写入应完成或失败而不阻塞
     */
    public function testPeerShutdownReadThenServerWriteAll(): void
    {
        [$srvB, $cliB] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($srvB, false);
        stream_set_blocking($cliB, false);

        $server = new Stream($srvB);
        $client = new Stream($cliB);

        go(function () use ($client) {
            Time::sleep(0.1);
            $client->shutdownRead();
            Time::sleep(0.9);
            $client->close();
        });

        $thrown = null;
        $written = 0;

        go(function () use ($server, &$thrown, &$written) {
            try {
                $payload = str_repeat('B', 1024 * 1024);
                $written = $server->writeAll($payload, 1.0);
            } catch (Throwable $e) {
                $thrown = $e;
            } finally {
                $server->close();
            }
        });

        wait();

        $this->assertTrue($written === (1024 * 1024) || $thrown instanceof Throwable);
    }
}
