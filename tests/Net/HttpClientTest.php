<?php declare(strict_types=1);

namespace Ripple\Tests\Net;

use Ripple\Net\Http;
use Ripple\Net\Http\Exception\TimeoutException;
use Ripple\Net\Http\Server\Request;
use Ripple\Process;
use Ripple\Stream;
use Ripple\Tests\Runtime\BaseTestCase;
use Ripple\Time;
use Throwable;

use function basename;
use function Co\go;
use function Co\wait;
use function file_exists;
use function file_put_contents;
use function filesize;
use function fopen;
use function is_file;
use function json_decode;
use function md5_file;
use function microtime;
use function mt_rand;
use function posix_kill;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const SIGKILL;

/**
 * HTTP 客户端测试
 */
class HttpClientTest extends BaseTestCase
{
    private const TEST_PORT_BASE = 19000;
    private const TEST_HOST = '127.0.0.1';

    /**
     * @var int|null
     */
    private ?int $serverPid = null;

    /**
     * @var string
     */
    private string $testUrl;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $currentPort = self::TEST_PORT_BASE + mt_rand(1, 1000);
        $this->testUrl = 'http://' . self::TEST_HOST . ':' . $currentPort;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->serverPid) {
            posix_kill($this->serverPid, SIGKILL);
            $this->serverPid = null;
        }
        parent::tearDown();
    }

    /**
     * 启动测试服务器
     */
    private function startTestServer(callable $requestHandler): void
    {
        $server = Http::server($this->testUrl);
        $server->onRequest = $requestHandler;
        $this->serverPid = Process::fork(static fn () => $server->listen());
        usleep(100000);
    }

    /**
     * @testdox GET请求应成功返回
     * @test
     * @throws Throwable
     */
    public function testGetRequest(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/get') {
                $request->respondJson([
                    'method' => 'GET',
                    'query' => $request->GET
                ]);
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, &$statusCode, &$data) {
            $client = Http::client(['timeout' => 10]);
            $response = $client->get($testUrl . '/get', [
                'query' => ['name' => 'ripple', 'version' => '2.0']
            ]);

            $statusCode = $response->statusCode();
            $data = json_decode($response->body(), true);
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('GET', $data['method']);
        $this->assertEquals('ripple', $data['query']['name']);
        $this->assertEquals('2.0', $data['query']['version']);
    }

    /**
     * @testdox POST JSON应成功发送
     * @test
     * @throws Throwable
     */
    public function testPostJson(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/post') {
                $body = json_decode($request->CONTENT, true);
                $request->respondJson([
                    'received' => $body
                ]);
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, &$statusCode, &$data) {
            $client = Http::client(['timeout' => 10]);
            $response = $client->post($testUrl . '/post', [
                'json' => ['message' => 'Hello Ripple!', 'timestamp' => 12345]
            ]);

            $statusCode = $response->statusCode();
            $data = json_decode($response->body(), true);
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('Hello Ripple!', $data['received']['message']);
        $this->assertEquals(12345, $data['received']['timestamp']);
    }

    /**
     * @testdox POST表单数据应成功发送
     * @test
     * @throws Throwable
     */
    public function testPostFormParams(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/form') {
                $request->respondJson([
                    'post' => $request->POST
                ]);
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, &$statusCode, &$data) {
            $client = Http::client(['timeout' => 10]);
            $response = $client->post($testUrl . '/form', [
                'form_params' => ['username' => 'ripple_user', 'action' => 'test']
            ]);

            $statusCode = $response->statusCode();
            $data = json_decode($response->body(), true);
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('ripple_user', $data['post']['username']);
        $this->assertEquals('test', $data['post']['action']);
    }

    /**
     * @testdox 小文件上传应成功且MD5一致
     * @test
     * @throws Throwable
     */
    public function testSmallFileUpload(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/upload' && $request->SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($request->FILES['file'])) {
                    $file = $request->FILES['file'][0];
                    $md5 = md5_file($file['path']);
                    $size = filesize($file['path']);
                    $request->respondJson([
                        'success' => true,
                        'md5' => $md5,
                        'size' => $size
                    ]);
                    @unlink($file['path']);
                } else {
                    $request->respondJson(['success' => false], [], 400);
                }
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($tmpFile, random_bytes(1024 * 100)); // 100KB
        $expectedMd5 = md5_file($tmpFile);

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, $tmpFile, &$statusCode, &$data) {
            try {
                $client = Http::client(['timeout' => 15]);
                $response = $client->post($testUrl . '/upload', [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tmpFile, 'rb'),
                            'filename' => basename($tmpFile)
                        ]
                    ]
                ]);

                $statusCode = $response->statusCode();
                $data = json_decode($response->body(), true);
            } finally {
                @unlink($tmpFile);
            }
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertTrue($data['success']);
        $this->assertEquals($expectedMd5, $data['md5']);
    }

    /**
     * @testdox 大文件上传应成功且MD5一致
     * @test
     * @throws Throwable
     */
    public function testLargeFileUpload(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/upload' && $request->SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($request->FILES['file'])) {
                    $file = $request->FILES['file'][0];
                    $md5 = md5_file($file['path']);
                    $size = filesize($file['path']);
                    $request->respondJson([
                        'success' => true,
                        'md5' => $md5,
                        'size' => $size
                    ]);
                    @unlink($file['path']);
                } else {
                    $request->respondJson(['success' => false], [], 400);
                }
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'large_upload_test_');
        file_put_contents($tmpFile, random_bytes(1024 * 1024 * 10)); // 10MB
        $expectedMd5 = md5_file($tmpFile);

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, $tmpFile, &$statusCode, &$data) {
            try {
                $client = Http::client(['timeout' => 30]);
                $response = $client->post($testUrl . '/upload', [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tmpFile, 'rb'),
                            'filename' => basename($tmpFile)
                        ]
                    ]
                ]);

                $statusCode = $response->statusCode();
                $data = json_decode($response->body(), true);
            } finally {
                @unlink($tmpFile);
            }
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertTrue($data['success']);
        $this->assertEquals($expectedMd5, $data['md5']);
    }

    /**
     * @testdox 文件下载应成功且MD5一致
     * @test
     * @throws Throwable
     */
    public function testFileDownload(): void
    {
        $serverFile = tempnam(sys_get_temp_dir(), 'server_download_');
        file_put_contents($serverFile, random_bytes(1024 * 1024)); // 1MB
        $serverMd5 = md5_file($serverFile);

        $this->startTestServer(static function (Request $request) use ($serverFile, $serverMd5) {
            if ($request->SERVER['REQUEST_URI'] === '/download') {
                if (is_file($serverFile)) {
                    $stream = new Stream(fopen($serverFile, 'rb'));
                    $request->response()
                        ->withHeader('Content-Type', 'application/octet-stream')
                        ->withHeader('Content-Disposition', 'attachment; filename="test.bin"')
                        ->withHeader('Content-MD5', $serverMd5)
                        ->withBody($stream)($request->conn->stream);
                }
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $fileExists = false;
        $downloadedMd5 = '';

        go(function () use ($testUrl, &$statusCode, &$fileExists, &$downloadedMd5) {
            $downloadPath = tempnam(sys_get_temp_dir(), 'downloaded_');

            try {
                $client = Http::client(['timeout' => 20]);
                $response = $client->get($testUrl . '/download', [
                    'sink' => $downloadPath
                ]);

                $statusCode = $response->statusCode();
                $fileExists = file_exists($downloadPath);
                $downloadedMd5 = md5_file($downloadPath);
            } finally {
                @unlink($downloadPath);
            }
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertTrue($fileExists);
        $this->assertEquals($serverMd5, $downloadedMd5);

        @unlink($serverFile);
    }

    /**
     * @testdox 延迟响应应在超时时间内成功
     * @test
     * @throws Throwable
     */
    public function testDelayResponse(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/delay/1') {
                Time::sleep(1);
                $request->respond('Delayed 1 second');
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $body = '';
        $elapsed = 0.0;

        go(function () use ($testUrl, &$statusCode, &$body, &$elapsed) {
            $start = microtime(true);
            $client = Http::client(['timeout' => 5]);
            $response = $client->get($testUrl . '/delay/1');
            $elapsed = microtime(true) - $start;

            $statusCode = $response->statusCode();
            $body = $response->body();
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('Delayed 1 second', $body);
        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        $this->assertLessThan(2.0, $elapsed);
    }

    /**
     * @testdox 超时请求应抛出TimeoutException
     * @test
     * @throws Throwable
     */
    public function testTimeoutException(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/delay/5') {
                Time::sleep(5);
                $request->respond('Should timeout');
            }
        });

        $testUrl = $this->testUrl;
        $exceptionThrown = false;
        $elapsed = 0.0;

        go(function () use ($testUrl, &$exceptionThrown, &$elapsed) {
            $start = microtime(true);

            try {
                $client = Http::client(['timeout' => 2]);
                $client->get($testUrl . '/delay/5');
            } catch (TimeoutException $e) {
                $exceptionThrown = true;
                $elapsed = microtime(true) - $start;
            }
        });

        wait();

        $this->assertTrue($exceptionThrown, 'TimeoutException应该被抛出');
        $this->assertLessThan(3.0, $elapsed);
    }

    /**
     * @testdox Client实例应支持base_uri
     * @test
     * @throws Throwable
     */
    public function testClientBaseUri(): void
    {
        $this->startTestServer(static function (Request $request) {
            $uri = $request->SERVER['REQUEST_URI'];
            if ($uri === '/api/users') {
                $request->respondJson(['users' => ['user1', 'user2']]);
            } elseif ($uri === '/api/posts') {
                $request->respondJson(['posts' => ['post1', 'post2']]);
            }
        });

        $testUrl = $this->testUrl;
        $statusCode1 = 0;
        $data1 = [];
        $statusCode2 = 0;
        $data2 = [];

        go(function () use ($testUrl, &$statusCode1, &$data1, &$statusCode2, &$data2) {
            $client = Http::client([
                'base_uri' => $testUrl . '/api',
                'timeout' => 10
            ]);

            $response1 = $client->get('/users');
            $statusCode1 = $response1->statusCode();
            $data1 = json_decode($response1->body(), true);

            $response2 = $client->get('/posts');
            $statusCode2 = $response2->statusCode();
            $data2 = json_decode($response2->body(), true);
        });

        wait();

        $this->assertEquals(200, $statusCode1);
        $this->assertCount(2, $data1['users']);
        $this->assertEquals(200, $statusCode2);
        $this->assertCount(2, $data2['posts']);
    }

    /**
     * @testdox 请求应支持自定义Headers
     * @test
     * @throws Throwable
     */
    public function testCustomHeaders(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/headers') {
                $request->respondJson([
                    'user_agent' => $request->SERVER['HTTP_USER_AGENT'] ?? '',
                    'custom_header' => $request->SERVER['HTTP_X_CUSTOM_HEADER'] ?? ''
                ]);
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, &$statusCode, &$data) {
            $client = Http::client([
                'timeout' => 10,
                'headers' => ['User-Agent' => 'Ripple-Test/1.0']
            ]);

            $response = $client->get($testUrl . '/headers', [
                'headers' => ['X-Custom-Header' => 'TestValue']
            ]);

            $statusCode = $response->statusCode();
            $data = json_decode($response->body(), true);
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('Ripple-Test/1.0', $data['user_agent']);
        $this->assertEquals('TestValue', $data['custom_header']);
    }

    /**
     * @testdox 空文件上传应成功
     * @test
     * @throws Throwable
     */
    public function testEmptyFileUpload(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/upload' && $request->SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($request->FILES['file'])) {
                    $file = $request->FILES['file'][0];
                    $md5 = md5_file($file['path']);
                    $request->respondJson([
                        'success' => true,
                        'md5' => $md5
                    ]);
                    @unlink($file['path']);
                } else {
                    $request->respondJson(['success' => false], [], 400);
                }
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'empty_upload_test_');
        file_put_contents($tmpFile, '');

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $data = [];

        go(function () use ($testUrl, $tmpFile, &$statusCode, &$data) {
            try {
                $client = Http::client(['timeout' => 10]);
                $response = $client->post($testUrl . '/upload', [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tmpFile, 'rb'),
                            'filename' => 'empty.txt'
                        ]
                    ]
                ]);

                $statusCode = $response->statusCode();
                $data = json_decode($response->body(), true);
            } finally {
                @unlink($tmpFile);
            }
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertTrue($data['success']);
        $this->assertEquals('d41d8cd98f00b204e9800998ecf8427e', $data['md5']);
    }

    /**
     * @testdox 请求超时可以被覆盖
     * @test
     * @throws Throwable
     */
    public function testTimeoutOverride(): void
    {
        $this->startTestServer(static function (Request $request) {
            if ($request->SERVER['REQUEST_URI'] === '/delay/2') {
                Time::sleep(2);
                $request->respond('Success');
            }
        });

        $testUrl = $this->testUrl;
        $statusCode = 0;
        $body = '';
        $elapsed = 0.0;

        go(function () use ($testUrl, &$statusCode, &$body, &$elapsed) {
            // Client默认超时1秒
            $client = Http::client(['timeout' => 1]);

            // 请求级别覆盖超时为6秒,应该成功
            $start = microtime(true);
            $response = $client->get($testUrl . '/delay/2', ['timeout' => 6]);
            $elapsed = microtime(true) - $start;

            $statusCode = $response->statusCode();
            $body = $response->body();
        });

        wait();

        $this->assertEquals(200, $statusCode);
        $this->assertEquals('Success', $body);
        $this->assertGreaterThanOrEqual(2.0, $elapsed);
    }
}
