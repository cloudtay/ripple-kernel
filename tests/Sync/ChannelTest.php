<?php declare(strict_types=1);

namespace Ripple\Tests\Sync;

use Ripple\Tests\Runtime\BaseTestCase;
use Ripple\Sync\Channel;
use Ripple\Time;
use RuntimeException;
use Throwable;

use function array_filter;
use function Co\go;
use function str_contains;

/**
 * Channel 测试
 */
class ChannelTest extends BaseTestCase
{
    /**
     * @testdox Unbuffered channel send receive (无缓冲通道的发送和接收)
     * @throws Throwable
     */
    public function testUnbufferedChannelSendReceive(): void
    {
        $channel = new Channel(0);
        $results = [];

        // 发送者协程
        go(function () use ($channel, &$results) {
            $channel->send("Hello");
            $results[] = "Sent: Hello";
        });

        // 接收者协程
        go(function () use ($channel, &$results) {
            $data = $channel->receive();
            $results[] = "Received: " . $data;
        });

        // 等待协程完成
        \Co\current()->suspend();

        $this->assertCount(2, $results);
        $this->assertContains("Sent: Hello", $results);
        $this->assertContains("Received: Hello", $results);
    }

    /**
     * @testdox Buffered channel (有缓冲通道)
     */
    public function testBufferedChannel(): void
    {
        $channel = new Channel(2);
        $results = [];

        // 发送多个数据
        go(function () use ($channel, &$results) {
            $channel->send("Message 1");
            $results[] = "Sent 1";
            $channel->send("Message 2");
            $results[] = "Sent 2";
            $channel->send("Message 3");
            $results[] = "Sent 3";
        });

        // 接收数据
        go(function () use ($channel, &$results) {
            Time::sleep(0.1);
            $data1 = $channel->receive();
            $results[] = "Received: " . $data1;
            $data2 = $channel->receive();
            $results[] = "Received: " . $data2;
            $data3 = $channel->receive();
            $results[] = "Received: " . $data3;
        });

        Time::sleep(0.2);

        $this->assertCount(6, $results);
        $this->assertContains("Received: Message 1", $results);
        $this->assertContains("Received: Message 2", $results);
        $this->assertContains("Received: Message 3", $results);
    }

    /**
     * @testdox TrySend method (测试trySend方法)
     */
    public function testTrySend(): void
    {
        $channel = new Channel(1);

        // 第一次发送应该成功
        $this->assertTrue($channel->trySend("Test"));

        // 第二次发送应该失败（缓冲区已满）
        $this->assertFalse($channel->trySend("Test2"));

        // 接收一个数据后, 应该可以再次发送
        $data = $channel->receive();
        $this->assertEquals("Test", $data);
        $this->assertTrue($channel->trySend("Test3"));
    }

    /**
     * @testdox TryReceive method (测试tryReceive方法)
     */
    public function testTryReceive(): void
    {
        $channel = new Channel(1);

        // 空通道应该返回false
        $value = null;
        $this->assertFalse($channel->tryReceive($value));
        $this->assertNull($value);

        // 发送数据后应该能接收
        $channel->send("Test");
        $this->assertTrue($channel->tryReceive($value));
        $this->assertEquals("Test", $value);
    }

    /**
     * @testdox Channel close (测试通道关闭)
     * @throws Throwable
     */
    public function testChannelClose(): void
    {
        $channel = new Channel(1);
        $results = [];

        // 发送一些数据
        $channel->send("Data1");

        // 尝试发送到已关闭的通道应该抛出异常
        go(function () use ($channel, &$results) {
            try {
                $channel->send("Data2");

                // 关闭通道
                $channel->close();
                $this->assertTrue($channel->isClosed());
                $channel->send("Should fail");
            } catch (RuntimeException) {
                $results[] = "Send failed";
            }
        });

        // 接收剩余数据
        go(function () use ($channel, &$results) {
            $data1 = $channel->receive();
            $results[] = "Received: " . $data1;

            $data2 = $channel->receive();
            $results[] = "Received: " . $data2;

            // 通道关闭后应该返回null
            $data3 = $channel->receive();
            $results[] = "Received: " . ($data3 === null ? 'null' : $data3);
        });

        \Co\current()->suspend();

        $this->assertContains("Send failed", $results);
        $this->assertContains("Received: Data1", $results);
        $this->assertContains("Received: Data2", $results);
        $this->assertContains("Received: null", $results);
    }

    /**
     * @testdox Channel capacity (测试通道容量)
     */
    public function testChannelCapacity(): void
    {
        $unbuffered = new Channel(0);
        $buffered = new Channel(5);

        $this->assertEquals(0, $unbuffered->capacity());
        $this->assertEquals(5, $buffered->capacity());
    }

    /**
     * @testdox Buffer size (测试缓冲区大小)
     */
    public function testBufferSize(): void
    {
        $channel = new Channel(3);

        $this->assertEquals(0, $channel->bufferSize());

        $channel->send("Data1");
        $this->assertEquals(1, $channel->bufferSize());

        $channel->send("Data2");
        $this->assertEquals(2, $channel->bufferSize());

        $channel->receive();
        $this->assertEquals(1, $channel->bufferSize());
    }

    /**
     * @testdox Waiting queue count (测试等待队列计数)
     */
    public function testWaitingCounts(): void
    {
        $channel = new Channel(0);

        // 创建等待接收的协程
        $receiver = go(function () use ($channel) {
            $channel->receive();
        });

        Time::sleep(0.05);
        $this->assertEquals(1, $channel->receiveWaitingCount());

        // 发送数据
        $channel->send("Data");
        Time::sleep(0.05);
        $this->assertEquals(0, $channel->receiveWaitingCount());
    }

    /**
     * @testdox Multiple senders and receivers (测试多个发送者和接收者)
     */
    public function testMultipleSendersReceivers(): void
    {
        $channel = new Channel(0);
        $results = [];

        // 创建多个发送者
        for ($i = 0; $i < 3; $i++) {
            go(function () use ($channel, $i, &$results) {
                $channel->send("Message from sender $i");
                $results[] = "Sender $i sent";
            });
        }

        // 创建多个接收者
        for ($i = 0; $i < 3; $i++) {
            go(function () use ($channel, $i, &$results) {
                $data = $channel->receive();
                $results[] = "Receiver $i got: $data";
            });
        }

        Time::sleep(0.2);

        $this->assertCount(6, $results);
        $this->assertCount(3, array_filter($results, fn ($r) => str_contains($r, "Sender")));
        $this->assertCount(3, array_filter($results, fn ($r) => str_contains($r, "Receiver")));
    }

    /**
     * @testdox Channel FIFO property (测试通道的FIFO特性)
     */
    public function testChannelFIFO(): void
    {
        $channel = new Channel(3);
        $received = [];

        // 发送多个数据
        for ($i = 1; $i <= 5; $i++) {
            $channel->send("Message $i");
        }

        // 接收数据
        go(function () use ($channel, &$received) {
            for ($i = 0; $i < 5; $i++) {
                $data = $channel->receive();
                $received[] = $data;
            }
        });

        \Co\wait();

        $this->assertCount(5, $received);
        $this->assertEquals("Message 1", $received[0]);
        $this->assertEquals("Message 2", $received[1]);
        $this->assertEquals("Message 3", $received[2]);
        $this->assertEquals("Message 4", $received[3]);
        $this->assertEquals("Message 5", $received[4]);
    }

    /**
     * @testdox Channel state queries (测试通道状态查询)
     */
    public function testChannelStateQueries(): void
    {
        $channel = new Channel(1);

        // 初始状态
        $this->assertFalse($channel->isClosed());
        $this->assertEquals(0, $channel->bufferSize());
        $this->assertEquals(0, $channel->sendWaitingCount());
        $this->assertEquals(0, $channel->receiveWaitingCount());

        // 发送数据后
        $channel->send("Test");
        $this->assertEquals(1, $channel->bufferSize());

        // 关闭后
        $channel->close();
        $this->assertTrue($channel->isClosed());
    }

    /**
     * @testdox Complex coroutine communication (测试协程间通信的复杂场景)
     * @throws Throwable
     */
    public function testComplexCoroutineCommunication(): void
    {
        $channel = new Channel(0);
        $results = [];

        // 生产者协程
        go(function () use ($channel, &$results) {
            for ($i = 1; $i <= 3; $i++) {
                $channel->send("Item $i");
                $results[] = "Produced: Item $i";
                Time::sleep(0.05);
            }
        });

        // 消费者协程
        go(function () use ($channel, &$results) {
            for ($i = 1; $i <= 3; $i++) {
                $item = $channel->receive();
                $results[] = "Consumed: $item";
                Time::sleep(0.05);
            }
        });

        \Co\current()->suspend();

        $this->assertCount(6, $results);
        $this->assertCount(3, array_filter($results, fn ($r) => str_contains($r, "Produced")));
        $this->assertCount(3, array_filter($results, fn ($r) => str_contains($r, "Consumed")));
    }
}
