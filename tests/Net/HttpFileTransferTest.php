<?php declare(strict_types=1);

namespace Ripple\Tests\Net;

use Ripple\Net\Http;
use Ripple\Net\Http\Server\Request;
use Ripple\Process;
use Ripple\Tests\Runtime\BaseTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

use function basename;
use function file_put_contents;
use function filesize;
use function fopen;
use function json_decode;
use function md5_file;
use function posix_kill;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;
use function mt_rand;
use function strval;

use const SIGKILL;

/**
 * HTTP 文件传输测试
 */
class HttpFileTransferTest extends BaseTestCase
{
    private const TEST_PORT_BASE = 8009;
    private const TEST_HOST = '127.0.0.1';

    private ?int $serverPid = null;
    private Client $client;
    private string $testUrl;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new Client();
        $currentPort = self::TEST_PORT_BASE + mt_rand(1, 1000);
        $this->testUrl = "http://" . self::TEST_HOST . ":" . $currentPort;
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
    private function startTestServer(): void
    {
        $server = Http::server($this->testUrl);
        $server->onRequest = static function (Request $request) {
            switch ($request->SERVER['REQUEST_URI']) {
                case '/upload':
                    $files = $request->FILES;
                    if (!empty($files['file'])) {
                        foreach ($files['file'] as $file) {
                            $md5 = md5_file($file['path']);
                            $request->respondJson([
                                'fileName' => $file['fileName'],
                                'md5'      => $md5,
                            ]);
                            return;
                        }
                    }
                    $request->respondJson(['error' => 'No file uploaded'], [], 400);
                    break;

                case '/download':

                    $filePath = tempnam(sys_get_temp_dir(), 'download_test_');
                    file_put_contents($filePath, random_bytes(1024 * 1024 * 20));
                    $md5 = md5_file($filePath);

                    $request->respond(
                        fopen($filePath, 'r'),
                        [
                            'Content-Type'        => 'application/octet-stream',
                            'Content-Disposition' => 'attachment; filename="test.bin"',
                            'Content-MD5'         => $md5,
                            'Content-Length'      => strval(filesize($filePath)),
                        ]
                    );

                    unlink($filePath);
                    break;

                default:
                    $request->respondJson(['error' => 'Not Found'], [], 404);
            }
        };

        $this->serverPid = Process::fork(static fn () => $server->listen());

        usleep(100000);
    }

    /**
     * @testdox 上传应成功并返回文件MD5
     * @test
     * @throws GuzzleException
     * @throws Throwable
     */
    public function testFileUpload(): void
    {
        $this->startTestServer();
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($tmpFile, random_bytes(1024 * 1024 * 2));
        $localMd5 = md5_file($tmpFile);

        try {
            $response = $this->client->request('POST', $this->testUrl . '/upload', [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($tmpFile, 'r'),
                        'filename' => basename($tmpFile),
                    ],
                ],
            ]);

            $this->assertEquals(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('fileName', $body);
            $this->assertArrayHasKey('md5', $body);
            $this->assertEquals($localMd5, $body['md5'], '上传文件MD5校验失败');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @testdox 下载应成功且本地MD5与Header一致
     * @test
     * @throws GuzzleException
     */
    public function testFileDownload(): void
    {
        $this->startTestServer();
        $downloadedFile = tempnam(sys_get_temp_dir(), 'downloaded_');

        try {
            $response = $this->client->request('GET', $this->testUrl . '/download', [
                'sink' => $downloadedFile,
            ]);

            $this->assertEquals(200, $response->getStatusCode());

            $serverMd5 = $response->getHeaderLine('Content-MD5');
            $localMd5  = md5_file($downloadedFile);
            $serverContentLength = $response->getHeaderLine('Content-Length');
            $localFileSize = filesize($downloadedFile);

            echo "serverMd5: $serverMd5\n";
            echo "localMd5: $localMd5\n";
            echo "serverContentLength: $serverContentLength\n";
            echo "localFileSize: $localFileSize\n";

            $this->assertNotEmpty($serverMd5, '服务器未返回Content-MD5头');
            $this->assertEquals($serverMd5, $localMd5, '下载文件MD5校验失败');

            $this->assertGreaterThan(0, filesize($downloadedFile), '下载的文件为空');
        } finally {
            unlink($downloadedFile);
        }
    }

    /**
     * @testdox 空文件上传应返回空内容MD5
     * @test
     * @throws GuzzleException
     */
    public function testFileUploadWithEmptyFile(): void
    {
        $this->startTestServer();
        $tmpFile = tempnam(sys_get_temp_dir(), 'empty_upload_test_');
        file_put_contents($tmpFile, '');

        try {
            $response = $this->client->request('POST', $this->testUrl . '/upload', [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($tmpFile, 'r'),
                        'filename' => basename($tmpFile),
                    ],
                ],
            ]);

            $this->assertEquals(200, $response->getStatusCode());

            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('md5', $body);
            $this->assertEquals('d41d8cd98f00b204e9800998ecf8427e', $body['md5'], '空文件MD5应该为d41d8cd98f00b204e9800998ecf8427e');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @testdox 未提供文件时应返回400与错误信息
     * @test
     */
    public function testFileUploadWithoutFile(): void
    {
        $this->startTestServer();

        try {
            $response = $this->client->request('POST', $this->testUrl . '/upload', [
                'multipart' => [],
                'http_errors' => false,
            ]);

            $this->assertEquals(400, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('error', $body);
            $this->assertEquals('No file uploaded', $body['error']);
        } catch (GuzzleException $e) {
            $this->fail('请求失败: ' . $e->getMessage());
        }
    }

    /**
     * @testdox 未知路径应返回404与错误信息
     * @test
     */
    public function testNotFoundEndpoint(): void
    {
        $this->startTestServer();

        try {
            $response = $this->client->request('GET', $this->testUrl . '/nonexistent', [
                'http_errors' => false,
            ]);

            $this->assertEquals(404, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('error', $body);
            $this->assertEquals('Not Found', $body['error']);
        } catch (GuzzleException $e) {
            $this->fail('请求失败: ' . $e->getMessage());
        }
    }

    /**
     * @testdox 大文件上传应成功且MD5匹配
     * @test
     * @throws GuzzleException
     * @throws Throwable
     */
    public function testLargeFileUpload(): void
    {
        $this->startTestServer();
        $tmpFile = tempnam(sys_get_temp_dir(), 'large_upload_test_');
        file_put_contents($tmpFile, random_bytes(1024 * 1024 * 10));
        $localMd5 = md5_file($tmpFile);

        try {
            $response = $this->client->request('POST', $this->testUrl . '/upload', [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($tmpFile, 'r'),
                        'filename' => basename($tmpFile),
                    ],
                ],
                'timeout' => 30,
            ]);

            $this->assertEquals(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('md5', $body);
            $this->assertEquals($localMd5, $body['md5'], '大文件上传MD5校验失败');
        } finally {
            unlink($tmpFile);
        }
    }
}
