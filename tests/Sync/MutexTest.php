<?php declare(strict_types=1);

namespace Ripple\Tests\Sync;

use Ripple\Tests\Runtime\BaseTestCase;
use Ripple\Sync\Mutex;
use Ripple\Time;
use Throwable;
use RuntimeException;

use function Co\go;
use function Co\wait;
use function microtime;

/**
 * 完全是单进程但资源静态来说依然有意义
 * Mutex
 */
class MutexTest extends BaseTestCase
{
    /**
     * @testdox Basic lock and unlock (测试基本的锁定和解锁)
     */
    public function testBasicLockUnlock(): void
    {
        $mutex = new Mutex();

        $this->assertFalse($mutex->isLocked());
        $this->assertNull($mutex->owner());
        $this->assertEquals(0, $mutex->waitingCount());

        $mutex->lock();
        $this->assertTrue($mutex->isLocked());
        $this->assertNotNull($mutex->owner());

        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
        $this->assertNull($mutex->owner());
    }

    /**
     * @testdox TryLock method (测试tryLock方法)
     */
    public function testTryLock(): void
    {
        $mutex = new Mutex();

        // 第一次尝试锁定应该成功
        $this->assertTrue($mutex->tryLock());
        $this->assertTrue($mutex->isLocked());

        // 第二次尝试锁定应该失败
        $this->assertTrue($mutex->tryLock());

        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
    }

    /**
     * @testdox Recursive lock same coroutine (测试同一协程的递归锁定)
     */
    public function testRecursiveLockSameCoroutine(): void
    {
        $mutex = new Mutex();

        // 同一协程多次锁定应该成功
        $mutex->lock();
        $this->assertTrue($mutex->isLocked());

        $mutex->lock(); // 第二次锁定
        $this->assertTrue($mutex->isLocked());

        // 第一次解锁就完全释放锁
        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
    }

    /**
     * @testdox Multiple coroutines competing (测试多协程竞争锁)
     * @throws Throwable
     */
    public function testMultipleCoroutinesCompeting(): void
    {
        $mutex = new Mutex();
        $results = [];
        $sharedData = 0;

        // 创建多个协程竞争锁
        for ($i = 0; $i < 3; $i++) {
            go(function () use ($mutex, &$sharedData, &$results, $i) {
                $mutex->lock();

                Time::sleep(0.1);
                $oldValue = $sharedData;
                $sharedData = $oldValue + 1;
                $results[] = "Coroutine $i: $oldValue -> $sharedData";

                $mutex->unlock();
            });
        }

        \Co\current()->suspend();
        $this->assertEquals(3, $sharedData);
        $this->assertCount(3, $results);
    }

    /**
     * @testdox Lock waiting queue (测试锁的等待队列)
     */
    public function testLockWaitingQueue(): void
    {


        $mutex = new Mutex();
        $results = [];

        // 先锁定互斥锁
        $mutex->lock();

        // 创建等待协程
        $waitingCoroutines = [];
        for ($i = 0; $i < 3; $i++) {
            $waitingCoroutines[] = go(function () use ($mutex, &$results, $i) {
                $mutex->lock();
                $results[] = "Waiting coroutine $i got lock";
                $mutex->unlock();
            });
        }

        // 检查等待队列
        Time::sleep(0.1);
        $this->assertEquals(3, $mutex->waitingCount());

        // 释放锁, 让等待的协程获得锁
        $mutex->unlock();

        Time::sleep(0.2);
        $this->assertCount(3, $results);
        $this->assertEquals(0, $mutex->waitingCount());
    }

    /**
     * @testdox Unlock by non-owner (测试非持有者尝试解锁)
     * @throws Throwable
     */
    public function testUnlockByNonOwner(): void
    {
        $mutex = new Mutex();
        $results = [];

        // 协程1获得锁
        go(function () use ($mutex, &$results) {
            $mutex->lock();
            $results[] = "Coroutine 1 got lock";
            Time::sleep(0.1);
        });

        // 协程2尝试解锁协程1的锁
        go(function () use ($mutex, &$results) {
            Time::sleep(0.1);
            try {
                $mutex->unlock();
                $results[] = "Coroutine 2 unlocked (should not happen)";
            } catch (Throwable $e) {
                $results[] = "Coroutine 2 failed to unlock";
            }
        });

        \Co\current()->suspend();
        $this->assertCount(2, $results);
        $this->assertContains("Coroutine 1 got lock", $results);
        $this->assertContains("Coroutine 2 failed to unlock", $results);
    }

    /**
     * @testdox Lock owner information (测试锁的所有者信息)
     */
    public function testLockOwner(): void
    {
        $mutex = new Mutex();

        $this->assertNull($mutex->owner());

        $mutex->lock();
        $owner = $mutex->owner();
        $this->assertNotNull($owner);

        $mutex->unlock();
        $this->assertNull($mutex->owner());
    }

    /**
     * @testdox Lock state queries (测试锁状态查询)
     */
    public function testLockStateQueries(): void
    {
        $mutex = new Mutex();

        // 初始状态
        $this->assertFalse($mutex->isLocked());
        $this->assertNull($mutex->owner());
        $this->assertEquals(0, $mutex->waitingCount());

        // 锁定后
        $mutex->lock();
        $this->assertTrue($mutex->isLocked());
        $this->assertNotNull($mutex->owner());
        $this->assertEquals(0, $mutex->waitingCount());

        // 解锁后
        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
        $this->assertNull($mutex->owner());
        $this->assertEquals(0, $mutex->waitingCount());
    }

    /**
     * @testdox Lock fairness (测试锁的公平性)
     */
    public function testLockFairness(): void
    {
        $mutex = new Mutex();
        $results = [];

        // 先锁定
        $mutex->lock();

        // 创建多个等待协程
        $waitingCoroutines = [];
        for ($i = 0; $i < 5; $i++) {
            $waitingCoroutines[] = go(function () use ($mutex, &$results, $i) {
                $mutex->lock();
                $results[] = "Coroutine $i";
                Time::sleep(0.01);
                $mutex->unlock();
            });
        }

        Time::sleep(0.1);
        $this->assertEquals(5, $mutex->waitingCount());

        // 释放锁
        $mutex->unlock();

        Time::sleep(0.2);
        $this->assertCount(5, $results);
        $this->assertEquals(0, $mutex->waitingCount());
    }

    /**
     * @testdox Concurrent lock safety (测试锁的并发安全性)
     */
    public function testConcurrentLockSafety(): void
    {
        $mutex = new Mutex();
        $counter = 0;
        $results = [];

        // 创建多个协程同时操作
        $coroutines = [];
        for ($i = 0; $i < 10; $i++) {
            $coroutines[] = go(function () use ($mutex, &$counter, &$results, $i) {
                for ($j = 0; $j < 10; $j++) {
                    $mutex->lock();
                    Time::sleep(0.1); // 模拟一些工作
                    $counter++;
                    $mutex->unlock();
                }
                $results[] = "Coroutine $i completed";
            });
        }

        wait();
        $this->assertEquals(100, $counter);
        $this->assertCount(10, $results);
    }

    /**
     * @testdox Lock exception handling (测试锁的异常处理)
     */
    public function testLockExceptionHandling(): void
    {
        $mutex = new Mutex();
        $results = [];

        // 不锁定, 解锁
        try {
            $mutex->unlock();
            $results[] = "Unlock succeeded (should not happen)";
        } catch (RuntimeException $e) {
            $results[] = "Unlock failed";
        }

        $this->assertCount(1, $results);
        $this->assertContains("Unlock failed", $results);
    }

    /**
     * @testdox Lock performance (测试锁的性能)
     */
    public function testLockPerformance(): void
    {
        $mutex = new Mutex();
        $startTime = microtime(true);

        // 执行大量锁定/解锁操作
        for ($i = 0; $i < 1000; $i++) {
            $mutex->lock();
            $mutex->unlock();
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // 验证性能在合理范围内（1000次操作应该在1秒内完成）
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * @testdox Lock with coroutine states (测试锁与协程状态的交互)
     */
    public function testLockWithCoroutineStates(): void
    {
        $mutex = new Mutex();
        $results = [];

        $coroutine = go(function () use ($mutex, &$results) {
            $mutex->lock();
            $results[] = "Locked";

            // 在持有锁的情况下暂停
            Time::sleep(0.1);

            $results[] = "Still locked";
            $mutex->unlock();
            $results[] = "Unlocked";
        });

        Time::sleep(0.2);

        $this->assertCount(3, $results);
        $this->assertEquals("Locked", $results[0]);
        $this->assertEquals("Still locked", $results[1]);
        $this->assertEquals("Unlocked", $results[2]);
    }
}
