<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Stream;
use Ripple\Time;

use function Co\go;
use function Co\wait;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class StreamEofCheckTest extends TestCase
{
    /**
     * @testdox 空读不等于 EOF, 半关闭后应检测到 EOF
     */
    public function testEmptyReadVsEof(): void
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);

        $srv = new Stream($server);
        $cli = new Stream($client);

        $observedEof = false;
        $gotData = false;

        go(function () use ($srv) {
            Time::sleep(0.2);
            $srv->writeAll("hello\n");
            Time::sleep(0.2);
            $srv->shutdownWrite();
        });

        go(function () use ($cli, &$observedEof, &$gotData) {
            for ($i = 0; $i < 100; $i++) {
                $data = $cli->read(1024);
                if ($data === '') {
                    if ($cli->eof()) {
                        $observedEof = true;
                        break;
                    }
                } else {
                    $gotData = true;
                }
                Time::sleep(0.02);
            }
            $cli->close();
        });

        wait();

        $this->assertTrue($gotData);
        $this->assertTrue($observedEof);
    }
}
