<?php declare(strict_types=1);

namespace Ripple\Tests\Runtime;

use Ripple\Runtime;
use Ripple\Coroutine;
use Ripple\Runtime\Scheduler;
use Ripple\Sync\Mutex;
use Ripple\Sync\WaitGroup;
use Ripple\Time;
use Ripple\Process;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Fiber;
use Ripple\Runtime\MainCoroutine;
use Ripple\Watch\Interface\WatchAbstract;
use Ripple\Watch\ExtEvWatcher;
use Ripple\Watch\ExtEventWatcher;
use Ripple\Watch\StreamWatcher;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

use function Co\defer;
use function Co\go;
use function get_class;
use function extension_loaded;
use function usleep;
use function pcntl_waitpid;

/**
 * 组合测试基础类
 */
abstract class CombinatorialTestCase extends BaseTestCase
{
    /**
     * 启动模式枚举
     */
    public const LAUNCH_MODES = [
        'queue' => '队列启动模式',
        'direct' => '直接启动模式',
        'main' => '主Main模式',
    ];

    /**
     * 基础特性枚举
     */
    public const BASIC_FEATURES = [
        'context_switch' => '上下文切换',
        'external_terminate' => '外部控制终止',
        'defer_cleanup' => 'defer清理机制',
    ];

    /**
     * 模块枚举
     */
    public const MODULES = [
        'coroutine' => 'Coroutine模块',
        'scheduler' => 'Scheduler模块',
        'runtime' => 'Runtime模块',
        'sync' => 'Sync模块',
        'time' => 'Time模块',
    ];

    /**
     * 进程模式枚举
     */
    public const PROCESS_MODES = [
        'single' => '单进程模式',
        'fork_child' => '子进程模式',
        'fork_parent' => '父进程模式',
    ];

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
     * 获取所有可能的组合
     * @return array
     */
    public static function getAllCombinations(): array
    {
        $combinations = [];

        foreach (self::LAUNCH_MODES as $launchMode => $launchDesc) {
            foreach (self::BASIC_FEATURES as $feature => $featureDesc) {
                foreach (self::MODULES as $module => $moduleDesc) {
                    foreach (self::PROCESS_MODES as $processMode => $processDesc) {
                        $combinations[] = [
                            'launch_mode' => $launchMode,
                            'feature' => $feature,
                            'module' => $module,
                            'process_mode' => $processMode,
                            'description' => "{$launchDesc}×{$featureDesc}×{$moduleDesc}×{$processDesc}"
                        ];
                    }
                }
            }
        }

        return $combinations;
    }

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
            $providers['EventWatcher'] = [ExtEventWatcher::class];
        }

        return $providers;
    }

    /**
     * 设置测试环境
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
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Scheduler::clear();
    }

    /**
     * 执行组合测试
     * @param string $launchMode 启动模式
     * @param string $feature 基础特性
     * @param string $module 模块
     * @param string $processMode 进程模式
     * @return array 执行结果
     */
    protected function executeCombinatorialTest(
        string $launchMode,
        string $feature,
        string $module,
        string $processMode
    ): array {
        $this->clearOutput();

        try {

            $coroutine = $this->createTestCoroutine($feature, $module);
            if ($processMode === 'fork_child' || $processMode === 'fork_parent') {
                return $this->executeInProcessMode($coroutine, $launchMode, $processMode);
            }

            $this->executeWithLaunchMode($coroutine, $launchMode);
            $this->waitForCompletion();

            return [
                'success' => true,
                'output' => $this->getOutput(),
                'coroutine_state' => $coroutine->state(),
                'errors' => $this->errors
            ];

        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            return [
                'success' => false,
                'output' => $this->getOutput(),
                'errors' => $this->errors,
                'exception' => $e->getMessage()
            ];
        }
    }

    /**
     * 创建测试协程
     * @param string $feature 基础特性
     * @param string $module 模块
     * @return Coroutine
     */
    protected function createTestCoroutine(string $feature, string $module): Coroutine
    {
        return Coroutine::create(function () use ($feature, $module) {

            if ($feature === 'defer_cleanup') {
                $this->applyDeferCleanup();
            }

            $this->executeModuleLogic($module);

            $this->applyBasicFeature($feature);

            $this->output[] = "completed_{$feature}_{$module}";
        });
    }

    /**
     * 根据启动模式执行协程
     * @param Coroutine $coroutine
     * @param string $launchMode
     * @return void
     * @throws Throwable
     */
    protected function executeWithLaunchMode(Coroutine $coroutine, string $launchMode): void
    {
        match ($launchMode) {
            'queue' => $this->executeInQueueMode($coroutine),
            'direct' => $this->executeInDirectMode($coroutine),
            'main' => $this->executeInMainMode($coroutine),
            default => throw new InvalidArgumentException("Unknown launch mode: {$launchMode}")
        };
    }

    /**
     * 队列启动模式：Scheduler::schedule($co)
     * @param Coroutine $coroutine
     * @return void
     */
    protected function executeInQueueMode(Coroutine $coroutine): void
    {
        Scheduler::enqueue($coroutine);
    }

    /**
     * 直接启动模式：\Co\go()
     * @param Coroutine $coroutine
     * @return void
     */
    protected function executeInDirectMode(Coroutine $coroutine): void
    {
        Scheduler::enqueue($coroutine, true);
    }

    /**
     * 主Main模式：在RuntimeMain中执行
     * @param Coroutine $coroutine
     * @return void
     * @throws Throwable
     * @throws Throwable
     */
    protected function executeInMainMode(Coroutine $coroutine): void
    {
        Scheduler::nextTick(function () use ($coroutine) {
            Scheduler::enqueue($coroutine, true);
        });
    }

    /**
     * 在进程模式下执行
     * @param Coroutine $coroutine
     * @param string $launchMode
     * @param string $processMode
     * @return array
     * @throws Throwable
     */
    protected function executeInProcessMode(Coroutine $coroutine, string $launchMode, string $processMode): array
    {
        if ($processMode === 'fork_child') {
            return $this->executeInChildProcess($coroutine, $launchMode);
        } elseif ($processMode === 'fork_parent') {
            return $this->executeInParentProcess($coroutine, $launchMode);
        }

        return ['success' => false, 'error' => 'Invalid process mode'];
    }

    /**
     * 在子进程中执行
     * @param Coroutine $coroutine
     * @param string $launchMode
     * @return array
     * @throws Throwable
     * @throws Throwable
     */
    protected function executeInChildProcess(Coroutine $coroutine, string $launchMode): array
    {
        $pid = Process::fork(function () use ($coroutine, $launchMode) {
            $this->executeWithLaunchMode($coroutine, $launchMode);
            $this->waitForCompletion();
            exit(0);
        });

        if ($pid > 0) {

            $status = 0;
            pcntl_waitpid($pid, $status);

            return [
                'success' => true,
                'child_pid' => $pid,
                'child_status' => $status,
                'output' => ['parent_waiting_for_child']
            ];
        }

        return ['success' => false, 'error' => 'Fork failed'];
    }

    /**
     * 在父进程中执行（子进程只做辅助工作）
     * @param Coroutine $coroutine
     * @param string $launchMode
     * @return array
     * @throws Throwable
     * @throws Throwable
     */
    protected function executeInParentProcess(Coroutine $coroutine, string $launchMode): array
    {
        $pid = Process::fork(function () {
            usleep(10000);
            exit(0);
        });

        if ($pid > 0) {

            $this->executeWithLaunchMode($coroutine, $launchMode);
            $this->waitForCompletion();

            $status = 0;
            pcntl_waitpid($pid, $status);

            return [
                'success' => true,
                'output' => $this->getOutput(),
                'coroutine_state' => $coroutine->state(),
                'child_pid' => $pid
            ];
        }

        return ['success' => false, 'error' => 'Fork failed'];
    }

    /**
     * 应用基础特性
     * @param string $feature
     * @return void
     * @throws Throwable
     */
    protected function applyBasicFeature(string $feature): void
    {
        match ($feature) {
            'context_switch' => $this->performContextSwitch(),
            'external_terminate' => $this->performExternalTerminate(),
            'defer_cleanup' => $this->performDeferCleanup(),
            default => $this->output[] = "unknown_feature_{$feature}"
        };
    }

    /**
     * 执行上下文切换
     * @return void
     * @throws Throwable
     * @throws Throwable
     */
    protected function performContextSwitch(): void
    {
        $currentCoroutine = \Co\current();
        $this->output[] = 'before_context_switch';

        Scheduler::nextTick(function () {
            $fiber = Fiber::getCurrent();
            $coroutine = \Co\current();
            $this->output[] = 'in_runtime_context';
            $this->output[] = 'fiber_is_null: ' . ($fiber === null ? 'yes' : 'no');
            $this->output[] = 'coroutine_class: ' . get_class($coroutine);
        });

        $this->output[] = 'after_context_switch';
    }

    /**
     * 执行外部控制终止
     * @return void
     */
    protected function performExternalTerminate(): void
    {
        $this->output[] = 'before_external_terminate';
        $currentCoroutine = \Co\current();

        go(function () use ($currentCoroutine) {
            Time::sleep(0.01);
            $this->output[] = 'external_terminating';

            if ($currentCoroutine->isSuspended()) {
                Scheduler::throw($currentCoroutine, new RuntimeException('external_termination'));
            }
        });

        try {
            $currentCoroutine->suspend();
        } catch (Throwable $e) {
            $this->output[] = 'terminated_by_external: ' . $e->getMessage();
        }
    }

    /**
     * 应用defer清理机制
     * @return void
     */
    protected function applyDeferCleanup(): void
    {
        defer(function () {
            $this->output[] = 'defer_executed';
        });
    }

    /**
     * 执行defer清理
     * @return void
     */
    protected function performDeferCleanup(): void
    {
        $this->output[] = 'defer_cleanup_feature_applied';
    }

    /**
     * 执行模块特定逻辑
     * @param string $module
     * @return void
     */
    protected function executeModuleLogic(string $module): void
    {
        match ($module) {
            'coroutine' => $this->executeCoroutineModule(),
            'scheduler' => $this->executeSchedulerModule(),
            'runtime' => $this->executeRuntimeModule(),
            'sync' => $this->executeSyncModule(),
            'time' => $this->executeTimeModule(),
            default => $this->output[] = "unknown_module_{$module}"
        };
    }

    /**
     * 执行Coroutine模块测试
     * @return void
     */
    protected function executeCoroutineModule(): void
    {
        $this->output[] = 'coroutine_module_start';

        $childCoroutine = Coroutine::create(function () {
            $this->output[] = 'child_coroutine_executed';
        });

        Scheduler::enqueue($childCoroutine);
        $this->output[] = 'coroutine_module_end';
    }

    /**
     * 执行Scheduler模块测试
     * @return void
     */
    protected function executeSchedulerModule(): void
    {
        $this->output[] = 'scheduler_module_start';
        $this->output[] = 'scheduler_queue_count: ' . Scheduler::runnableCount();
        $this->output[] = 'scheduler_module_end';
    }

    /**
     * 执行Runtime模块测试
     * @return void
     */
    protected function executeRuntimeModule(): void
    {
        $this->output[] = 'runtime_module_start';
        $coroutine = Runtime::main();
        $this->output[] = 'runtime_coroutine_state: ' . $coroutine->state();
        $this->output[] = 'runtime_module_end';
    }

    /**
     * 执行Sync模块测试
     * @return void
     */
    protected function executeSyncModule(): void
    {
        $this->output[] = 'sync_module_start';

        $mutex = new Mutex();
        go(function () use ($mutex) {
            $mutex->lock();
            $this->output[] = 'mutex_locked_co1';
            Time::sleep(0.001);
            $mutex->unlock();
        });

        go(function () use ($mutex) {
            $mutex->lock();
            $this->output[] = 'mutex_locked_co2';
            $mutex->unlock();
        });

        $wg = new WaitGroup();
        $wg->add(2);
        go(function () use ($wg) {
            Time::sleep(0.001);
            $wg->done();
        });
        go(function () use ($wg) {
            Time::sleep(0.001);
            $wg->done();
        });
        $wg->wait();
        $this->output[] = 'waitgroup_done';
        $this->output[] = 'sync_module_end';
    }

    /**
     * 执行Time模块测试
     * @return void
     */
    protected function executeTimeModule(): void
    {
        $this->output[] = 'time_module_start';


        Time::sleep(0.01);
        $this->output[] = 'time_sleep_completed';

        $this->output[] = 'time_module_end';
    }

    /**
     * 等待所有事件完成（唯一不挂起模式）
     * @return void
     * @throws Throwable
     * @throws Throwable
     */
    protected function waitForCompletion(): void
    {
        $co = \Co\current();
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
     * 模拟资源等待
     * @param float $seconds
     * @return void
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
}
