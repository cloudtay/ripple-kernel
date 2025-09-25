<?php declare(strict_types=1);

namespace Ripple\Tests\Runtime\Combinatorial;

use Ripple\Tests\Runtime\CombinatorialTestCase;

use function count;
use function memory_get_usage;
use function microtime;
use function array_slice;
use function implode;
use function sprintf;

/**
 * 维度测试
 */
class ExponentialCombinatorialTest extends CombinatorialTestCase
{
    /**
     * 数据提供器：所有组合
     * @return array
     */
    public static function allCombinationsProvider(): array
    {
        $combinations = parent::getAllCombinations();
        $result = [];

        foreach ($combinations as $index => $combination) {

            $launchModeDesc = self::LAUNCH_MODES[$combination['launch_mode']];
            $featureDesc = self::BASIC_FEATURES[$combination['feature']];
            $moduleDesc = self::MODULES[$combination['module']];
            $processModeDesc = self::PROCESS_MODES[$combination['process_mode']];

            $detailedDescription = sprintf(
                "第%03d组合: %s × %s × %s × %s",
                $index + 1,
                $launchModeDesc,
                $featureDesc,
                $moduleDesc,
                $processModeDesc
            );

            $combination['detailed_description'] = $detailedDescription;
            $combination['index'] = $index + 1;

            $result[$detailedDescription] = [$combination];
        }

        return $result;
    }

    /**
     * 数据提供器：核心组合（减少测试数量用于快速验证）
     * @return array
     */
    public static function coreCombinationsProvider(): array
    {
        $coreCombinations = [];
        $index = 1;

        foreach (self::LAUNCH_MODES as $launchMode => $launchDesc) {
            foreach (self::BASIC_FEATURES as $feature => $featureDesc) {

                $coreModules = ['coroutine', 'runtime', 'time'];
                foreach ($coreModules as $module) {
                    $moduleDesc = self::MODULES[$module];
                    $processModeDesc = self::PROCESS_MODES['single'];

                    $detailedDescription = sprintf(
                        "核心第%02d组合: %s × %s × %s × %s",
                        $index,
                        $launchDesc,
                        $featureDesc,
                        $moduleDesc,
                        $processModeDesc
                    );

                    $combination = [
                        'launch_mode' => $launchMode,
                        'feature' => $feature,
                        'module' => $module,
                        'process_mode' => 'single',
                        'description' => "{$launchDesc}×{$featureDesc}×{$moduleDesc}×{$processModeDesc}",
                        'detailed_description' => $detailedDescription,
                        'index' => $index
                    ];

                    $coreCombinations[$detailedDescription] = [$combination];
                    $index++;
                }
            }
        }

        return $coreCombinations;
    }

    /**
     * @testdox 核心组合测试 (验证主要功能组合的基本执行)
     * @dataProvider coreCombinationsProvider
     * @param array $combination
     * @return void
     */
    public function testCoreCombinations(array $combination): void
    {
        $this->addToAssertionCount(1);
        echo "执行: {$combination['detailed_description']}\n";

        $result = $this->executeCombinatorialTest(
            $combination['launch_mode'],
            $combination['feature'],
            $combination['module'],
            $combination['process_mode']
        );

        if ($result['success']) {
            echo "成功 - 输出条目数: " . count($result['output']) . "\n";
        } else {
            echo "失败 - 错误: " . implode(', ', $result['errors'] ?? []) . "\n";
        }

        $this->assertTrue(
            $result['success'],
            sprintf(
                "组合执行失败\n" .
                "组合详情: %s\n" .
                "启动模式: %s\n" .
                "基础特性: %s\n" .
                "测试模块: %s\n" .
                "进程模式: %s\n" .
                "错误信息: %s\n" .
                "输出内容: %s",
                $combination['detailed_description'],
                self::LAUNCH_MODES[$combination['launch_mode']],
                self::BASIC_FEATURES[$combination['feature']],
                self::MODULES[$combination['module']],
                self::PROCESS_MODES[$combination['process_mode']],
                implode(', ', $result['errors'] ?? ['无']),
                implode(', ', $result['output'] ?? ['无输出'])
            )
        );

        $this->assertNotEmpty(
            $result['output'],
            sprintf(
                "组合 %s 没有产生任何输出\n" .
                "🔧 启动模式: %s | ⚡ 特性: %s | 模块: %s",
                $combination['detailed_description'],
                self::LAUNCH_MODES[$combination['launch_mode']],
                self::BASIC_FEATURES[$combination['feature']],
                self::MODULES[$combination['module']]
            )
        );

        $expectedCompletion = "completed_{$combination['feature']}_{$combination['module']}";
        $this->assertContains(
            $expectedCompletion,
            $result['output'],
            sprintf(
                "组合 %s 没有正确完成\n" .
                "期望标记: %s\n" .
                "实际输出: %s",
                $combination['detailed_description'],
                $expectedCompletion,
                implode(', ', $result['output'])
            )
        );
    }

    /**
     * @testdox 完整组合测试 (测试所有可能的组合)
     * @dataProvider allCombinationsProvider
     * @param array $combination
     * @return void
     */
    public function testAllCombinations(array $combination): void
    {
        $this->addToAssertionCount(1);

        $result = $this->executeCombinatorialTest(
            $combination['launch_mode'],
            $combination['feature'],
            $combination['module'],
            $combination['process_mode']
        );

        $this->assertTrue(
            $result['success'],
            sprintf(
                "第%d个组合执行失败\n" .
                "详细描述: %s\n" .
                "启动模式: %s (%s)\n" .
                "基础特性: %s (%s)\n" .
                "测试模块: %s (%s)\n" .
                "进程模式: %s (%s)\n" .
                "错误详情: %s",
                $combination['index'],
                $combination['detailed_description'],
                $combination['launch_mode'],
                self::LAUNCH_MODES[$combination['launch_mode']],
                $combination['feature'],
                self::BASIC_FEATURES[$combination['feature']],
                $combination['module'],
                self::MODULES[$combination['module']],
                $combination['process_mode'],
                self::PROCESS_MODES[$combination['process_mode']],
                implode(' | ', $result['errors'] ?? ['无错误信息'])
            )
        );
    }

    /**
     * @testdox 启动模式对比测试 (对比不同启动模式的行为差异)
     * @return void
     */
    public function testLaunchModeComparison(): void
    {
        $results = [];
        $feature = 'context_switch';
        $module = 'coroutine';
        $processMode = 'single';

        foreach (self::LAUNCH_MODES as $launchMode => $description) {
            $results[$launchMode] = $this->executeCombinatorialTest(
                $launchMode,
                $feature,
                $module,
                $processMode
            );
        }

        foreach ($results as $mode => $result) {
            $this->assertTrue($result['success'], "启动模式 {$mode} 执行失败");
        }

        $queueOutput = $results['queue']['output'];
        $directOutput = $results['direct']['output'];
        $mainOutput = $results['main']['output'];

        foreach ([$queueOutput, $directOutput, $mainOutput] as $output) {
            $this->assertContains("completed_{$feature}_{$module}", $output);
        }
    }

    /**
     * @testdox 基础特性对比测试 (验证不同基础特性的行为)
     * @return void
     */
    public function testBasicFeaturesComparison(): void
    {
        $results = [];
        $launchMode = 'direct';
        $module = 'coroutine';
        $processMode = 'single';

        foreach (self::BASIC_FEATURES as $feature => $description) {
            $results[$feature] = $this->executeCombinatorialTest(
                $launchMode,
                $feature,
                $module,
                $processMode
            );
        }

        foreach ($results as $feature => $result) {
            $this->assertTrue($result['success'], "基础特性 {$feature} 执行失败");
        }

        $this->assertContains('before_context_switch', $results['context_switch']['output']);
        $this->assertContains('after_context_switch', $results['context_switch']['output']);
        $this->assertContains('defer_executed', $results['defer_cleanup']['output']);
    }

    /**
     * @testdox 模块功能测试 (验证所哟模块的基本功能)
     * @return void
     */
    public function testModuleFunctionality(): void
    {
        $results = [];
        $launchMode = 'direct';
        $feature = 'context_switch';
        $processMode = 'single';

        foreach (self::MODULES as $module => $description) {
            $results[$module] = $this->executeCombinatorialTest(
                $launchMode,
                $feature,
                $module,
                $processMode
            );
        }

        foreach ($results as $module => $result) {
            $this->assertTrue($result['success'], "模块 {$module} 执行失败");
        }

        $this->assertContains('coroutine_module_start', $results['coroutine']['output']);
        $this->assertContains('child_coroutine_executed', $results['coroutine']['output']);

        $this->assertContains('scheduler_module_start', $results['scheduler']['output']);
        $this->assertStringContainsString(
            'scheduler_queue_count:',
            implode(' ', $results['scheduler']['output'])
        );

        $this->assertContains('runtime_module_start', $results['runtime']['output']);
        $this->assertStringContainsString(
            'runtime_coroutine_state:',
            implode(' ', $results['runtime']['output'])
        );

        $this->assertContains('time_module_start', $results['time']['output']);
        $this->assertContains('time_sleep_completed', $results['time']['output']);
    }

    /**
     * @testdox 进程模式测试 (验证不同进程模式的行为)
     * @return void
     */
    public function testProcessModes(): void
    {
        $results = [];
        $launchMode = 'direct';
        $feature = 'context_switch';
        $module = 'coroutine';

        foreach (self::PROCESS_MODES as $processMode => $description) {
            $results[$processMode] = $this->executeCombinatorialTest(
                $launchMode,
                $feature,
                $module,
                $processMode
            );
        }

        foreach ($results as $mode => $result) {
            $this->assertTrue($result['success'], "进程模式 {$mode} 执行失败");
        }

        $singleResult = $results['single'];
        $this->assertContains("completed_{$feature}_{$module}", $singleResult['output']);

        if (isset($results['fork_child']['child_pid'])) {
            $this->assertGreaterThan(0, $results['fork_child']['child_pid']);
        }

        if (isset($results['fork_parent']['child_pid'])) {
            $this->assertGreaterThan(0, $results['fork_parent']['child_pid']);
            $this->assertContains("completed_{$feature}_{$module}", $results['fork_parent']['output']);
        }
    }

    /**
     * @testdox 性能基准测试 (验证各种组合的执行时间)
     * @return void
     */
    public function testPerformanceBenchmark(): void
    {
        $benchmarkResults = [];
        $coreCombinations = array_slice(self::coreCombinationsProvider(), 0, 10);

        foreach ($coreCombinations as [$combination]) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            $result = $this->executeCombinatorialTest(
                $combination['launch_mode'],
                $combination['feature'],
                $combination['module'],
                $combination['process_mode']
            );

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $benchmarkResults[] = [
                'combination' => $combination['description'],
                'success' => $result['success'],
                'execution_time' => $endTime - $startTime,
                'memory_used' => $endMemory - $startMemory,
            ];
        }

        foreach ($benchmarkResults as $benchmark) {
            $this->assertTrue(
                $benchmark['success'],
                "性能测试组合失败: {$benchmark['combination']}"
            );

            $this->assertLessThan(
                2.0,
                $benchmark['execution_time'],
                "组合 {$benchmark['combination']} 执行时间过长: {$benchmark['execution_time']}s"
            );

            $this->assertLessThan(
                5 * 1024 * 1024,
                $benchmark['memory_used'],
                "组合 {$benchmark['combination']} 内存使用过多: {$benchmark['memory_used']} bytes"
            );
        }
    }

    /**
     * @testdox 稳定性测试 (多次执行同一组合验证结果一致性)
     * @return void
     */
    public function testStability(): void
    {
        $combination = [
            'launch_mode' => 'direct',
            'feature' => 'context_switch',
            'module' => 'coroutine',
            'process_mode' => 'single'
        ];

        $results = [];
        $iterations = 5;

        for ($i = 0; $i < $iterations; $i++) {
            $this->clearOutput();

            $result = $this->executeCombinatorialTest(
                $combination['launch_mode'],
                $combination['feature'],
                $combination['module'],
                $combination['process_mode']
            );

            $results[] = $result;
        }

        foreach ($results as $index => $result) {
            $this->assertTrue($result['success'], "第 {$index} 次执行失败");
        }

        $firstResult = $results[0];
        foreach ($results as $index => $result) {
            $this->assertSameSize(
                $firstResult['output'],
                $result['output'],
                "第 {$index} 次执行的输出数量不一致"
            );
        }
    }

    /**
     * @testdox defer机制测试 (验证defer清理机制在各种组合下的正确性)
     * @return void
     */
    public function testDeferMechanism(): void
    {
        foreach (self::LAUNCH_MODES as $launchMode => $description) {
            $result = $this->executeCombinatorialTest(
                $launchMode,
                'defer_cleanup',
                'coroutine',
                'single'
            );

            $this->assertTrue(
                $result['success'],
                "defer机制在 {$launchMode} 模式下失败"
            );

            $this->assertContains(
                'defer_executed',
                $result['output'],
                "defer机制在 {$launchMode} 模式下没有执行"
            );
        }
    }

    /**
     * @testdox 并发执行测试 (验证多个组合同时执行的正确性)
     * @return void
     */
    public function testConcurrentExecution(): void
    {
        $combinations = [
            ['direct', 'context_switch', 'coroutine', 'single'],
            ['queue', 'defer_cleanup', 'runtime', 'single'],
        ];

        $results = [];
        $startTime = microtime(true);

        foreach ($combinations as $index => $combination) {
            $results[$index] = $this->executeCombinatorialTest(...$combination);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        foreach ($results as $index => $result) {
            $this->assertTrue($result['success'], "并发执行第 {$index} 个组合失败");
        }

        $this->assertLessThan(
            3.0,
            $totalTime,
            "并发执行时间过长: {$totalTime}s"
        );
    }
}
