<?php declare(strict_types=1);

namespace Ripple\Tests\Runtime;

use Ripple\Runtime\FiberCoroutine;
use Ripple\Runtime;
use Ripple\Coroutine;
use Ripple\Runtime\MainCoroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Time;
use Ripple\Watch\Interface\WatchAbstract;
use Ripple\Watch\ExtEvWatcher;
use Ripple\Watch\ExtEventWatcher;
use Ripple\Watch\StreamWatcher;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Throwable;

use function extension_loaded;
use function usleep;

/**
 * 基础
 */
abstract class BaseTestCase extends PHPUnitTestCase
{
    /**
     * @var WatchAbstract
     */
    protected WatchAbstract $watcher;

    /**
     * 测试输出收集
     * @var array
     */
    protected array $output = [];

    /**
     * 错误收集
     * @var array
     */
    protected array $errors = [];

    /**
     * 获取可用的watcher类型
     * @return array<string, array{0: class-string<WatchAbstract>}>
     */
    public static function watcherProvider(): array
    {
        $providers = [];
        $providers['StreamWatcher'] = [StreamWatcher::class];

        if (extension_loaded('ev')) {
            $providers['ExtEvWatcher'] = [ExtEvWatcher::class];
        }

        if (extension_loaded('event')) {
            $providers['ExtEventWatcher'] = [ExtEventWatcher::class];
        }

        return $providers;
    }

    /**
     * 创建watcher实例
     * @param class-string<WatchAbstract> $watcherClass
     * @return WatchAbstract
     */
    protected function createWatcher(string $watcherClass): WatchAbstract
    {
        return new $watcherClass();
    }

    /**
     * 设置测试环境
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->output = [];
        $this->errors = [];

        Runtime::init();
    }

    /**
     * 清理测试环境
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Scheduler::clear();
    }

    /**
     * 使用指定的watcher类重新设置测试环境
     * @param string $watcherClass
     * @return void
     */
    protected function setUpWithWatcher(string $watcherClass): void
    {
        $this->output = [];
        $this->errors = [];

        $this->watcher = $this->createWatcher($watcherClass);
        Runtime::init();
    }

    /**
     * 模拟资源等待
     * @param float $seconds
     * @return void
     * @throws Throwable
     */
    protected function mockResource(float $seconds): void
    {
        $co = \Co\current();

        if ($co instanceof MainCoroutine) {
            usleep((int)($seconds * 1000000));
            return;
        }

        Time::sleep($seconds);
    }

    /**
     * 等待所有协程完成
     * @return void
     * @throws Throwable
     * @throws Throwable
     */
    protected function waitForCompletion(): void
    {
        $co = \Co\current();
        if ($co instanceof MainCoroutine && $co->state() !== Coroutine::STATE_RUNNING) {
            return;
        }

        $co->suspend();
    }

    /**
     * 获取输出结果
     * @return array
     */
    protected function getOutput(): array
    {
        return $this->output;
    }

    /**
     * 清空输出
     * @return void
     */
    protected function clearOutput(): void
    {
        $this->output = [];
        $this->errors = [];
    }

    /**
     * 添加输出
     * @param mixed $message
     * @return void
     */
    protected function addOutput(mixed $message): void
    {
        $this->output[] = $message;
    }

    /**
     * 添加错误
     * @param string $error
     * @return void
     */
    protected function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * 检查Runtime是否处于保护模式
     * @return bool
     */
    protected function isRuntimeProtected(): bool
    {
        return false;
    }

    /**
     * 如果Runtime处于保护模式则跳过测试
     * @return void
     */
    protected function skipIfRuntimeProtected(): void
    {
        if ($this->isRuntimeProtected()) {
            $this->markTestSkipped('Test skipped due to RuntimeMain protection mode');
        }
    }

    /**
     * 创建测试协程
     * @param callable $callback
     * @return Coroutine
     */
    protected function createTestCoroutine(callable $callback): Coroutine
    {
        return Coroutine::create($callback);
    }

    /**
     * 运行协程并等待完成
     * @param FiberCoroutine $coroutine
     * @return void
     * @throws Throwable
     */
    protected function runCoroutineAndWait(FiberCoroutine $coroutine): void
    {
        $coroutine->runnable();
        Scheduler::enqueue($coroutine);
        $this->waitForCompletion();
    }

    /**
     * 断言协程状态
     * @param string $expectedState
     * @param FiberCoroutine $coroutine
     * @param string $message
     * @return void
     */
    protected function assertCoroutineState(string $expectedState, FiberCoroutine $coroutine, string $message = ''): void
    {
        $this->assertEquals($expectedState, $coroutine->state(), $message);
    }

    /**
     * 断言协程已完成
     * @param FiberCoroutine $coroutine
     * @param string $message
     * @return void
     */
    protected function assertCoroutineCompleted(FiberCoroutine $coroutine, string $message = ''): void
    {
        $this->assertCoroutineState(Coroutine::STATE_DEAD, $coroutine, $message);
    }

    /**
     * 断言协程正在运行
     * @param FiberCoroutine $coroutine
     * @param string $message
     * @return void
     */
    protected function assertCoroutineRunning(FiberCoroutine $coroutine, string $message = ''): void
    {
        $this->assertCoroutineState(Coroutine::STATE_RUNNING, $coroutine, $message);
    }

    /**
     * 断言协程已暂停
     * @param FiberCoroutine $coroutine
     * @param string $message
     * @return void
     */
    protected function assertCoroutineSuspended(FiberCoroutine $coroutine, string $message = ''): void
    {
        $this->assertCoroutineState(Coroutine::STATE_WAITING, $coroutine, $message);
    }
}
