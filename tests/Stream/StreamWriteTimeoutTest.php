<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Stream;
use Ripple\Time;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function Co\go;
use function Co\wait;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strtolower;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class StreamWriteTimeoutTest extends TestCase
{
    /**
     * @testdox 背压下写入超时应抛出连接异常
     */
    public function testWriteAllTimeoutUnderBackpressure(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $srv = new Stream($server, [
            'write_buffer_size' => 32768,
            'write_buffer_max'  => 64 * 1024 * 1024,
            'write_chunk_size'  => 61440,
        ]);
        $cli = new Stream($client);

        // 客户端不读取, 制造背压并稍后关闭
        go(function () use ($cli) {
            Time::sleep(2.0);
            $cli->shutdownRead();
            Time::sleep(1.0);
            $cli->close();
        });

        $thrown = null;

        go(function () use ($srv, &$thrown) {
            try {
                $payload = str_repeat('X', 8 * 1024 * 1024);
                $srv->writeAll($payload, 1);
            } catch (Throwable $e) {
                $thrown = $e;
            } finally {
                $srv->close();
            }
        });

        wait();

        $this->assertNotNull($thrown);
        $this->assertInstanceOf(ConnectionException::class, $thrown);
        $message = ($thrown instanceof Throwable) ? $thrown->getMessage() : '';
        $this->assertStringContainsString('timeout', strtolower($message));
    }
}
