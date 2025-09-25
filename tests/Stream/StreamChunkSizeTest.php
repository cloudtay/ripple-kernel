<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Runtime;
use Ripple\Stream;
use Ripple\Time;

use function Co\go;
use function Co\wait;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

Runtime::$DEBUG = true;

final class StreamChunkSizeTest extends TestCase
{
    /**
     * @testdox 小写入分片也应完整传输大数据
     */
    public function testWriteAllWithSmallChunkSize(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $srv = new Stream($server, ['write_chunk_size' => 8 * 1024]);
        $cli = new Stream($client);

        $total = 0;

        go(function () use ($cli, &$total) {
            $emptyReads = 0;
            while (true) {
                Time::sleep(0.001);
                $data = $cli->read(8192);
                if ($data === '') {
                    if ($cli->eof()) {
                        break;
                    }

                    if (++$emptyReads > 1000) {
                        break;
                    }

                    continue;
                }
                $emptyReads = 0;
                $total += strlen($data);
            }
            $cli->close();
        });

        go(function () use ($srv) {
            $payload = str_repeat('Z', 512 * 1024);
            $srv->writeAll($payload);
            $srv->shutdownWrite();
            $srv->close();
        });

        wait();
        $this->assertSame(512 * 1024, $total);
        $this->assertTrue(true);
    }
}
