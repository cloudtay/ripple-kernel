<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
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

final class StreamAsyncFlushTest extends TestCase
{
    /**
     * @testdox 异步写入与单次刷新应保持写入总量守恒
     */
    public function testAsyncWriteWithManualFlush(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $srv = new Stream($server, ['write_chunk_size' => 32 * 1024]);
        $cli = new Stream($client);

        $totalClientRead = 0;
        $finalBuffered = 0;
        $intended = 10240 * 100; // 100 * 10KB

        go(function () use ($cli, &$totalClientRead) {
            while (true) {
                $data = $cli->read(4096);
                if ($data === '' && $cli->eof()) {
                    break;
                }
                $totalClientRead += strlen($data);
                Time::sleep(0.001);
            }
            $cli->close();
        });

        go(function () use ($srv, &$finalBuffered) {
            $chunks = 100;
            for ($i = 0; $i < $chunks; $i++) {
                $srv->writeAsync(str_repeat('A', 10 * 1024));
                if ($i % 5 === 0) {
                    $srv->flushOnce();
                }
                Time::sleep(0.001);
            }

            $round = 0;
            while (!$srv->writeBuffer()->isEmpty() && $round < 500) {
                $srv->flushOnce();
                $round++;
                Time::sleep(0.001);
            }

            $finalBuffered = $srv->writeBuffer()->length();
            $srv->shutdownWrite();
            $srv->close();
        });

        wait();

        $this->assertGreaterThan(0, $totalClientRead);
        $this->assertSame($intended, $totalClientRead + $finalBuffered);
    }
}
