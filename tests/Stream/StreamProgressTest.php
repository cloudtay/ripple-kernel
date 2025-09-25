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

final class StreamProgressTest extends TestCase
{
    /**
     * @testdox 写入进度与读取累计应一致
     */
    public function testWriteReadProgressConsistency(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $serverStream = new Stream($server);
        $clientStream = new Stream($client);

        $totalLength = 0;
        $writtenTotal = 0;

        go(function () use ($serverStream, &$writtenTotal) {
            $data = str_repeat('A', 1024 * 1024);
            $writtenTotal = $serverStream->writeAll($data);
        });

        go(function () use ($clientStream, $serverStream, &$totalLength) {
            while (true) {
                $content = $clientStream->read(1024);
                if ($content === '') {
                    $clientStream->close();
                    break;
                }
                $totalLength += strlen($content);
                Time::sleep(0.01);
            }
        });

        wait();

        $this->assertSame($writtenTotal, $totalLength);
    }
}
