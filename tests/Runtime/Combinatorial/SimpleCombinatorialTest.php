<?php declare(strict_types=1);

namespace Ripple\Tests\Runtime\Combinatorial;

use Ripple\Tests\Runtime\CombinatorialTestCase;

use function array_slice;
use function count;
use function implode;

/**
 * 快速版本
 */
class SimpleCombinatorialTest extends CombinatorialTestCase
{
    /**
     * @testdox 基本启动模式测试 (验证三种启动模式的基本功能)
     * @return void
     */
    public function testBasicLaunchModes(): void
    {
        $feature = 'context_switch';
        $module = 'coroutine';
        $processMode = 'single';

        echo "\n测试三种启动模式的基本功能\n";
        echo "测试配置: 上下文切换 × Coroutine模块 × 单进程模式\n\n";

        echo "1. 测试队列启动模式 (Scheduler::schedule)\n";
        $queueResult = $this->executeCombinatorialTest('queue', $feature, $module, $processMode);
        $this->assertTrue(
            $queueResult['success'],
            "队列启动模式失败\n错误: " . implode(', ', $queueResult['errors'] ?? [])
        );
        $this->assertContains("completed_{$feature}_{$module}", $queueResult['output']);
        echo "队列启动模式通过\n\n";

        echo "2. 测试直接启动模式 (立即调度)\n";
        $directResult = $this->executeCombinatorialTest('direct', $feature, $module, $processMode);
        $this->assertTrue(
            $directResult['success'],
            "直接启动模式失败\n错误: " . implode(', ', $directResult['errors'] ?? [])
        );
        $this->assertContains("completed_{$feature}_{$module}", $directResult['output']);
        echo "直接启动模式通过\n\n";

        echo "3. 测试主Main模式 (Runtime::call)\n";
        $mainResult = $this->executeCombinatorialTest('main', $feature, $module, $processMode);
        $this->assertTrue(
            $mainResult['success'],
            "主Main模式失败\n错误: " . implode(', ', $mainResult['errors'] ?? [])
        );
        $this->assertContains("completed_{$feature}_{$module}", $mainResult['output']);
        echo "主Main模式通过\n\n";

        echo "所有启动模式测试完成\n";
    }

    /**
     * @testdox 基础特性测试 (验证四种基础特性的功能)
     * @return void
     */
    public function testBasicFeatures(): void
    {
        $launchMode = 'direct';
        $module = 'coroutine';
        $processMode = 'single';

        $contextResult = $this->executeCombinatorialTest($launchMode, 'context_switch', $module, $processMode);
        $this->assertTrue($contextResult['success'], '上下文切换特性失败');
        $this->assertContains('before_context_switch', $contextResult['output']);
        $this->assertContains('after_context_switch', $contextResult['output']);

        $panicResult = $this->executeCombinatorialTest($launchMode, 'panic_throw', $module, $processMode);
        $this->assertTrue($panicResult['success'], '异常抛入特性失败');

        $deferResult = $this->executeCombinatorialTest($launchMode, 'defer_cleanup', $module, $processMode);
        $this->assertTrue($deferResult['success'], 'defer清理特性失败');
        $this->assertContains('defer_executed', $deferResult['output']);
    }

    /**
     * @testdox 核心模块测试 (验证核心模块的基本功能)
     * @return void
     */
    public function testCoreModules(): void
    {
        $launchMode = 'direct';
        $feature = 'context_switch';
        $processMode = 'single';

        $coroutineResult = $this->executeCombinatorialTest($launchMode, $feature, 'coroutine', $processMode);
        $this->assertTrue($coroutineResult['success'], 'Coroutine模块测试失败');
        $this->assertContains('coroutine_module_start', $coroutineResult['output']);
        $this->assertContains('child_coroutine_executed', $coroutineResult['output']);

        $runtimeResult = $this->executeCombinatorialTest($launchMode, $feature, 'runtime', $processMode);
        $this->assertTrue($runtimeResult['success'], 'Runtime模块测试失败');
        $this->assertContains('runtime_module_start', $runtimeResult['output']);

        $timeResult = $this->executeCombinatorialTest($launchMode, $feature, 'time', $processMode);
        $this->assertTrue($timeResult['success'], 'Time模块测试失败');
        $this->assertContains('time_module_start', $timeResult['output']);
        $this->assertContains('time_sleep_completed', $timeResult['output']);
    }

    /**
     * @testdox 组合效果验证 (验证不同组合产生不同的执行效果)
     * @return void
     */
    public function testCombinationEffects(): void
    {

        $combo1 = $this->executeCombinatorialTest('queue', 'context_switch', 'coroutine', 'single');
        $this->assertTrue($combo1['success'], '组合1执行失败');

        $combo2 = $this->executeCombinatorialTest('direct', 'panic_throw', 'time', 'single');
        $this->assertTrue($combo2['success'], '组合2执行失败');

        $combo3 = $this->executeCombinatorialTest('main', 'defer_cleanup', 'runtime', 'single');
        $this->assertTrue($combo3['success'], '组合3执行失败');

        $this->assertNotEquals($combo1['output'], $combo2['output'], '不同组合应该产生不同输出');
        $this->assertNotEquals($combo2['output'], $combo3['output'], '不同组合应该产生不同输出');
        $this->assertNotEquals($combo1['output'], $combo3['output'], '不同组合应该产生不同输出');
    }

    /**
     * @testdox defer机制在不同启动模式下的行为 (验证defer在各种启动模式下都能正确工作)
     * @return void
     */
    public function testDeferInDifferentLaunchModes(): void
    {
        $feature = 'defer_cleanup';
        $module = 'coroutine';
        $processMode = 'single';

        foreach (self::LAUNCH_MODES as $launchMode => $description) {
            $result = $this->executeCombinatorialTest($launchMode, $feature, $module, $processMode);

            $this->assertTrue(
                $result['success'],
                "defer机制在{$description}下失败"
            );

            $this->assertContains(
                'defer_executed',
                $result['output'],
                "defer机制在{$description}下没有执行"
            );
        }
    }

    /**
     * @testdox 架构完整性验证 (验证新架构的基本完整性)
     * @return void
     */
    public function testArchitectureIntegrity(): void
    {
        $this->assertCount(3, self::LAUNCH_MODES, '启动模式数量不正确');
        $this->assertCount(3, self::BASIC_FEATURES, '基础特性数量不正确');
        $this->assertCount(5, self::MODULES, '模块数量不正确');
        $this->assertCount(3, self::PROCESS_MODES, '进程模式数量不正确');

        $totalCombinations =
            count(self::LAUNCH_MODES) * count(self::BASIC_FEATURES) *
            count(self::MODULES) * count(self::PROCESS_MODES);

        $this->assertEquals(135, $totalCombinations, '总组合数应该是135');

        $allCombinations = parent::getAllCombinations();
        $this->assertCount(135, $allCombinations, '生成的组合数量不正确');

        foreach (array_slice($allCombinations, 0, 5) as $combination) {
            $this->assertArrayHasKey('launch_mode', $combination);
            $this->assertArrayHasKey('feature', $combination);
            $this->assertArrayHasKey('module', $combination);
            $this->assertArrayHasKey('process_mode', $combination);
            $this->assertArrayHasKey('description', $combination);
        }
    }
}
