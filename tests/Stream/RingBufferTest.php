<?php declare(strict_types=1);

namespace Ripple\Tests\Stream;

use PHPUnit\Framework\TestCase;
use Ripple\Stream\RingBuffer;
use ReflectionClass;
use Throwable;

use function str_repeat;
use function strlen;
use function substr;
use function min;
use function random_int;
use function pack;
use function max;

/**
 * 测试专用随机字节生成
 * @param int $length 需要生成的字节长度
 * @return string 指定长度的字节串
 * @throws Throwable
 */
function test_random_bytes(int $length): string
{
    $out = '';
    while (strlen($out) < $length) {
        $val = random_int(0, 0xFFFFFFFF);
        $out .= pack('N', $val);
    }
    return substr($out, 0, $length);
}

final class RingBufferTest extends TestCase
{
    /**
     * @testdox 基础写读应保持顺序与长度一致
     * @return void
     */
    public function testWriteReadBasicIntegrity(): void
    {
        $rb = new RingBuffer(1024);
        $data = str_repeat('A', 100) . str_repeat('B', 200) . str_repeat('C', 300);

        $written = $rb->write($data);
        $this->assertSame(strlen($data), $written);
        $this->assertSame(strlen($data), $rb->length());

        $read = $rb->read(150);
        $this->assertSame(substr($data, 0, 150), $read);
        $this->assertSame(strlen($data) - 150, $rb->length());

        $readRest = $rb->read(1000);
        $this->assertSame(substr($data, 150), $readRest);
        $this->assertTrue($rb->isEmpty());
    }

    /**
     * @testdox 环绕写读应保持顺序与长度正确
     * @return void
     * @throws Throwable
     */
    public function testWrapAroundReadWrite(): void
    {
        $rb = new RingBuffer(1024);

        // 第一次写 800
        $chunk1 = test_random_bytes(800);
        $rb->write($chunk1);

        // 读 600, 使 readPos 前移, length=200
        $this->assertSame(substr($chunk1, 0, 600), $rb->read(600));
        $this->assertSame(200, $rb->length());

        // 再写 700（不会扩容, 触发环绕两段写）
        $chunk2 = test_random_bytes(700);
        $rb->write($chunk2);
        $this->assertSame(900, $rb->length());


        // 读取并校验数据完整性（先剩余 200 of chunk1, 再是 chunk2）
        $expected = substr($chunk1, 600) . $chunk2;
        $this->assertSame($expected, $rb->read(900));
        $this->assertTrue($rb->isEmpty());
    }

    /**
     * @testdox 跨界 peek 应返回连续视图且不消耗数据
     * @return void
     */
    public function testPeekDoesNotConsumeAcrossBoundary(): void
    {
        $rb = new RingBuffer(64);
        $rb->write(str_repeat('X', 40));
        $rb->read(32);
        // 使读指针靠近尾部
        $rb->write(str_repeat('Y', 40));
        // 触发环绕

        $peek = $rb->peek(16);
        $this->assertSame('XXXXXXXX' . 'YYYYYYYY', $peek);
        // 8 X + 8 Y
        $this->assertSame(48, $rb->length());
        // peek 不消耗

        $read = $rb->read(16);
        $this->assertSame($peek, $read);
    }

    /**
     * @testdox 扩容应按增长因子并二次幂对齐
     * @return void
     */
    public function testExpandGrowthFactorAndPowerOfTwo(): void
    {
        $rb = new RingBuffer(1024);
        $rb->write(str_repeat('A', 800));
        // no expand

        // 这次写入会触发扩容：required=800+500=1300, growth=1.5*1024=1536 -> nextPow2(1536)=2048
        $rb->write(str_repeat('B', 500));
        $this->assertGreaterThanOrEqual(2048, $rb->capacity());


        // 校验按序读出
        $this->assertSame(str_repeat('A', 800), $rb->read(800));
        $this->assertSame(str_repeat('B', 500), $rb->read(500));
        $this->assertTrue($rb->isEmpty());
    }

    /**
     * @testdox 跨界低利用率应触发 compact 并重排指针
     * @return void
     * @throws Throwable
     */
    public function testCompactTriggeredOnWrappedLowUtilization(): void
    {
        // 固定容量为 1024, 阈值=256
        $rb = new RingBuffer(1024);

        // 1) 写入 700 -> writePos=700, length=700
        $rb->write(test_random_bytes(700));

        // 2) 读出 600 -> readPos=600, writePos=700, length=100
        $rb->read(600);

        // 3) 再写 340 -> 先写 324 到尾部, 再写 16 到头部 -> writePos=16, length=440
        $rb->write(test_random_bytes(340));

        // 预期内的话 readPos(600) >= writePos(16) 成立, 数据跨越

        // 4) 读出 200（不跨界, 因为到尾部还有 424）-> readPos=800, length=240 (<256)
        $rb->read(200);

        // 本次 read 完成后 shouldCompact() 成立, read() 内部应执行 compact()
        $ref = new ReflectionClass($rb);
        $rp = $ref->getProperty('readPos');
        $wp = $ref->getProperty('writePos');
        $this->assertSame(0, $rp->getValue($rb));
        $this->assertSame($rb->length(), $wp->getValue($rb));
    }

    /**
     * @testdox clear 应只重置状态不改变容量
     * @return void
     * @throws Throwable
     */
    public function testClearResetsStateButKeepsCapacity(): void
    {
        $rb = new RingBuffer(128);
        $rb->write(test_random_bytes(100));
        $cap = $rb->capacity();
        $rb->clear();
        $this->assertTrue($rb->isEmpty());
        $this->assertSame($cap, $rb->capacity());
        $this->assertSame('', $rb->read(10));
    }

    /**
     * @testdox write/read/peek 的零长度边界行为应正确
     * @return void
     */
    public function testZeroLengthOperations(): void
    {
        $rb = new RingBuffer(64);
        $this->assertSame(0, $rb->write(''));
        $this->assertSame('', $rb->read(0));
        $this->assertSame('', $rb->peek(0));
    }

    /**
     * @testdox 超大写入应被容量钳制且写入量与剩余空间一致
     * @return void
     */
    public function testWriteTooLargeClampedAtMaxCapacity(): void
    {
        $rb = new RingBuffer(1024);

        // 写入一个非常大的块, 逼近或超过 MAX_CAPACITY
        $huge = str_repeat('Z', 32 * 1024 * 1024);

        // 32MB
        $written = $rb->write($huge);

        // length 不会超过 capacity, 且 capacity 不会超过 MAX_CAPACITY
        $this->assertSame($rb->length(), $written);
        $this->assertSame($rb->capacity(), $rb->length());

        // 再次写入应为 0（已满）
        $this->assertSame(0, $rb->write('X'));

        // 读出部分后可再写
        $this->assertNotSame('', $rb->read(1));
        $this->assertSame(1, $rb->write('Y'));
    }

    /**
     * @testdox 连续数据低利用率时不应 compact
     * @return void
     * @throws Throwable
     */
    public function testNoCompactWhenDataIsContiguous(): void
    {
        $rb = new RingBuffer(512);

        // 阈值=128
        $rb->write(test_random_bytes(200));

        // 连续
        $rb->read(150);

        // length=50 < 128, 但 readPos<writePos (连续) -> 不应 compact
        $ref = new ReflectionClass($rb);
        $rp = $ref->getProperty('readPos');
        $wp = $ref->getProperty('writePos');
        $this->assertNotSame(0, $rp->getValue($rb));
        $this->assertNotSame($rb->length(), $wp->getValue($rb));
    }

    /**
     * @testdox 频繁小块写读应保持顺序与长度统计一致
     * @return void
     * @throws Throwable
     */
    public function testStressAlternatingSmallWritesAndReads(): void
    {
        $rb = new RingBuffer(256);
        $log = '';
        for ($i = 0; $i < 500; $i++) {
            $w = random_int(1, 50);
            $chunk = test_random_bytes($w);
            $written = $rb->write($chunk);
            $this->assertSame(min($w, $rb->capacity() - ($rb->length() - $written)), $written);

            $r = random_int(0, 50);
            $read = $rb->read($r);
            $this->assertSame(min($r, $written + strlen($log)), max(strlen($read), 0));
            $log .= substr($chunk, 0, $written);
            $log = substr($log, strlen($read));
        }

        // drain
        $drain = $rb->read(1 << 20);
        $this->assertSame($log, $drain);
        $this->assertTrue($rb->isEmpty());
    }

    /**
     * @testdox 扩容后再 compact 应保持读写顺序不变
     * @return void
     */
    public function testExpandThenCompactKeepsOrder(): void
    {
        $rb = new RingBuffer(128);
        $a = str_repeat('A', 100);
        $rb->write($a);

        // 触发展开：再写 200 -> required=300, growth=192 -> nextPow2(300)=512
        $rb->write(str_repeat('B', 200));
        $this->assertGreaterThanOrEqual(256, $rb->capacity());
        $first = $rb->read(250);

        // 剩余 50
        $this->assertSame($a . str_repeat('B', 150), $first);

        // 制造跨界
        $rb->write(str_repeat('C', 180));

        // 拉到阈值以下并触发 compact
        $rb->read(150);
        $rest = $rb->read(1 << 20);

        // 在上述步骤中, read(150) 会先消费残留的 B50 再消费 C100, 剩余应为 C80
        $this->assertSame(str_repeat('C', 80), $rest);
        $this->assertTrue($rb->isEmpty());
    }

    /**
     * @testdox peek 超长应返回全部数据且不改变长度
     * @return void
     */
    public function testPeekFullAndOverLength(): void
    {
        $rb = new RingBuffer(64);
        $rb->write(str_repeat('Q', 40));
        $this->assertSame(str_repeat('Q', 40), $rb->peek(64));

        // over length
        $this->assertSame(40, $rb->length());
        $this->assertSame(str_repeat('Q', 40), $rb->read(64));
        $this->assertTrue($rb->isEmpty());
    }
}
