<?php declare(strict_types=1);

namespace Ripple\Tests\Sync;

use Ripple\Tests\Runtime\BaseTestCase;
use Ripple\Sync\WaitGroup;
use Ripple\Time;
use Throwable;
use RuntimeException;

use function array_filter;
use function Co\go;
use function microtime;
use function str_contains;
use function get_class;

/**
 * WaitGroup
 */
class WaitGroupTest extends BaseTestCase
{
    /**
     * @testdox Basic add and done operations (测试基本的add和done操作)
     */
    public function testBasicAddDone(): void
    {
        $wg = new WaitGroup();

        $this->assertEquals(0, $wg->counter());
        $this->assertEquals(0, $wg->waitingCount());

        $wg->add(3);
        $this->assertEquals(3, $wg->counter());

        $wg->done();
        $this->assertEquals(2, $wg->counter());

        $wg->done();
        $this->assertEquals(1, $wg->counter());

        $wg->done();
        $this->assertEquals(0, $wg->counter());
    }

    /**
     * @testdox Wait functionality (测试等待功能)
     */
    public function testWait(): void
    {



        $wg = new WaitGroup();
        $results = [];

        $wg->add(2);

        // 创建两个工作协程
        $worker1 = go(function () use ($wg, &$results) {
            Time::sleep(0.1);
            $results[] = "Worker 1 done";
            $wg->done();
        });

        $worker2 = go(function () use ($wg, &$results) {
            Time::sleep(0.2);
            $results[] = "Worker 2 done";
            $wg->done();
        });

        // 等待所有工作完成
        $waiter = go(function () use ($wg, &$results) {
            $wg->wait();
            $results[] = "All workers completed";
        });

        Time::sleep(0.3);

        $this->assertCount(3, $results);
        $this->assertContains("Worker 1 done", $results);
        $this->assertContains("Worker 2 done", $results);
        $this->assertContains("All workers completed", $results);
        $this->assertEquals(0, $wg->counter());
    }

    /**
     * @testdox Multiple waiters (测试多个等待者)
     */
    public function testMultipleWaiters(): void
    {
        $wg = new WaitGroup();
        $results = [];

        $wg->add(1);

        // 创建多个等待者
        $waiters = [];
        for ($i = 0; $i < 3; $i++) {
            $waiters[] = go(function () use ($wg, &$results, $i) {
                $wg->wait();
                $results[] = "Waiter $i completed";
            });
        }

        // 创建工作协程
        $worker = go(function () use ($wg, &$results) {
            Time::sleep(0.1);
            $results[] = "Worker done";
            $wg->done();
        });

        Time::sleep(0.2);

        $this->assertCount(4, $results);
        $this->assertContains("Worker done", $results);
        $this->assertCount(3, array_filter($results, fn ($r) => str_contains($r, "Waiter")));
    }

    /**
     * @testdox Wait with zero counter (测试计数器为0时的等待)
     */
    public function testWaitWithZeroCounter(): void
    {
        $wg = new WaitGroup();
        $results = [];

        // 计数器为0时, wait应该立即返回
        $waiter = go(function () use ($wg, &$results) {
            $wg->wait();
            $results[] = "Wait completed immediately";
        });

        Time::sleep(0.1);

        $this->assertCount(1, $results);
        $this->assertContains("Wait completed immediately", $results);
    }

    /**
     * @testdox Negative add operation (测试负数add操作)
     */
    public function testNegativeAdd(): void
    {
        $wg = new WaitGroup();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("WaitGroup counter cannot be negative");

        // 这个调用应该抛出异常
        $wg->add(-1);
    }

    /**
     * @testdox Done operation underflow (测试done操作导致计数器下溢)
     */
    public function testDoneUnderflow(): void
    {
        $wg = new WaitGroup();

        try {
            $wg->done();
        } catch (Throwable $e) {
            $this->assertTrue(get_class($e) === RuntimeException::class);
        }
    }

    /**
     * @testdox Complex waiting scenario (测试复杂的等待场景)
     */
    public function testComplexWaitingScenario(): void
    {
        $wg = new WaitGroup();
        $results = [];

        $wg->add(5);

        // 创建多个工作协程, 完成时间不同
        $workers = [];
        for ($i = 0; $i < 5; $i++) {
            $workers[] = go(function () use ($wg, &$results, $i) {
                Time::sleep(($i + 1) * 0.05); // 不同的完成时间
                $results[] = "Worker $i completed";
                $wg->done();
            });
        }

        // 创建等待者
        $waiter = go(function () use ($wg, &$results) {
            $wg->wait();
            $results[] = "All workers finished";
        });

        Time::sleep(0.4);

        $this->assertCount(6, $results);
        $this->assertContains("All workers finished", $results);
        $this->assertEquals(0, $wg->counter());
    }

    /**
     * @testdox Waiting queue count (测试等待队列计数)
     */
    public function testWaitingCount(): void
    {
        $wg = new WaitGroup();

        $wg->add(1);

        // 创建等待者
        $waiter1 = go(function () use ($wg) {
            $wg->wait();
        });

        $waiter2 = go(function () use ($wg) {
            $wg->wait();
        });

        Time::sleep(0.05);
        $this->assertEquals(2, $wg->waitingCount());

        // 完成工作
        $wg->done();
        Time::sleep(0.05);
        $this->assertEquals(0, $wg->waitingCount());
    }

    /**
     * @testdox Multiple add operations (测试多次add操作)
     */
    public function testMultipleAddOperations(): void
    {
        $wg = new WaitGroup();

        $wg->add(2);
        $this->assertEquals(2, $wg->counter());

        $wg->add(3);
        $this->assertEquals(5, $wg->counter());

        $wg->add(1);
        $this->assertEquals(6, $wg->counter());

        // 完成所有工作
        for ($i = 0; $i < 6; $i++) {
            $wg->done();
        }

        $this->assertEquals(0, $wg->counter());
    }

    /**
     * @testdox WaitGroup concurrent safety (测试WaitGroup的并发安全性)
     */
    public function testConcurrentSafety(): void
    {
        $wg = new WaitGroup();
        $results = [];

        $wg->add(10);

        // 创建多个协程同时调用done
        $workers = [];
        for ($i = 0; $i < 10; $i++) {
            $workers[] = go(function () use ($wg, &$results, $i) {
                Time::sleep(0.01);
                $wg->done();
                $results[] = "Worker $i done";
            });
        }

        // 创建等待者
        $waiter = go(function () use ($wg, &$results) {
            $wg->wait();
            $results[] = "All done";
        });

        Time::sleep(0.2);

        $this->assertCount(11, $results);
        $this->assertContains("All done", $results);
        $this->assertEquals(0, $wg->counter());
    }

    /**
     * @testdox WaitGroup state queries (测试WaitGroup状态查询)
     */
    public function testStateQueries(): void
    {
        $wg = new WaitGroup();

        // 初始状态
        $this->assertEquals(0, $wg->counter());
        $this->assertEquals(0, $wg->waitingCount());

        // 添加工作
        $wg->add(3);
        $this->assertEquals(3, $wg->counter());
        $this->assertEquals(0, $wg->waitingCount());

        // 添加等待者
        $waiter = go(function () use ($wg) {
            $wg->wait();
        });

        Time::sleep(0.05);
        $this->assertEquals(3, $wg->counter());
        $this->assertEquals(1, $wg->waitingCount());

        // 完成工作
        $wg->done();
        $this->assertEquals(2, $wg->counter());

        $wg->done();
        $this->assertEquals(1, $wg->counter());

        $wg->done();
        $this->assertEquals(0, $wg->counter());
        $this->assertEquals(0, $wg->waitingCount());
    }

    /**
     * @testdox WaitGroup performance (测试WaitGroup的性能)
     */
    public function testPerformance(): void
    {
        $wg = new WaitGroup();
        $startTime = microtime(true);

        $wg->add(1000);

        // 快速完成所有工作
        for ($i = 0; $i < 1000; $i++) {
            $wg->done();
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // 验证性能在合理范围内
        $this->assertLessThan(0.1, $duration);
        $this->assertEquals(0, $wg->counter());
    }
}
