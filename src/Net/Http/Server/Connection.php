<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Net\Http\Server;

use Ripple\Runtime\Scheduler;
use RuntimeException;
use Ripple\Net\Http\Enum\Method;
use Ripple\Net\Http\Server;
use Ripple\Net\Exception\FormatException;
use Ripple\Net\Http\Server\Upload\Multipart;
use Ripple\Runtime\Support\Stdin;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function array_merge;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function parse_str;
use function preg_match;
use function str_contains;
use function strlen;
use function strpos;
use function strtok;
use function strtoupper;
use function substr;
use function strtolower;
use function ctype_upper;
use function str_starts_with;
use function parse_url;
use function strtr;
use function intval;

use const PHP_URL_PATH;

/**
 * HTTP连接处理器
 */
class Connection
{
    private const COMMON_HEADERS = [
        'HOST' => 'HTTP_HOST',
        'USER-AGENT' => 'HTTP_USER_AGENT',
        'ACCEPT' => 'HTTP_ACCEPT',
        'ACCEPT-LANGUAGE' => 'HTTP_ACCEPT_LANGUAGE',
        'ACCEPT-ENCODING' => 'HTTP_ACCEPT_ENCODING',
        'CONNECTION' => 'HTTP_CONNECTION',
        'CONTENT-TYPE' => 'HTTP_CONTENT_TYPE',
        'CONTENT-LENGTH' => 'HTTP_CONTENT_LENGTH',
    ];

    /**
     * 步骤常量
     */
    private const STEP_INITIAL = 0;        // 初始
    private const STEP_CONTINUOUS = 1;     // 连续传输
    private const STEP_COMPLETE = 2;       // 完成步骤
    private const STEP_FILE_TRANSFER = 3;  // 文件传输步骤

    /**
     * 请求解析步骤
     * @var int
     */
    private int $step;

    /**
     * Query 查询参数数组
     * @var array
     */
    private array $query;

    /**
     * 请求体参数数组
     * @var array
     */
    private array $request;

    /**
     * 请求属性数组
     * @var array
     */
    private array $attributes;

    /**
     * Cookie 数组
     * @var array
     */
    private array $cookies;

    /**
     * 上传文件数组
     * @var array
     */
    private array $files;

    /**
     * 服务器环境变量数组
     * @var array
     */
    private array $server;

    /**
     * HTTP 请求体内容
     * @var string
     */
    private string $content;

    /**
     * 数据接收缓冲区
     * @var string
     */
    private string $buf = '';

    /**
     * Multipart数据处理器
     * @var Multipart|null
     */
    private Multipart|null $multipart;

    /**
     * 已接收的请求体长度
     * @var int
     */
    private int $bodySize;

    /**
     * 请求体总长度
     * @var int
     */
    private int $contentLength;

    /**
     * 服务实例引用
     * @var Server|null
     */
    private ?Server $httpServer = null;

    /**
     * @var mixed|string
     */
    private string $contentType;

    /**
     * @var Method
     */
    private Method $method;

    /**
     * 构造函数
     * @param Stream $stream 流对象
     * @param array $serverInfo 服务器信息
     */
    public function __construct(public readonly Stream $stream, private readonly array $serverInfo = [])
    {
        $this->reset();
    }

    /**
     * 启动连接处理
     * @param Server $server 服务器实例
     * @return void
     */
    public function start(Server $server): void
    {
        $this->httpServer = $server;
        try {
            $this->stream->watchRead(function () {
                try {
                    $content = $this->stream->read(8192);
                    if ($content === '' && $this->stream->eof()) {
                        $this->disconnect();
                        return;
                    }

                    foreach ($this->processData($content) as $reqInfo) {
                        $this->onRequest($reqInfo);
                    }
                } catch (ConnectionException) {
                    $this->disconnect();
                } catch (Throwable $err) {
                    Stdin::println($err->getMessage());
                    $this->disconnect();
                }
            });
        } catch (ConnectionException) {
            $this->disconnect();
        }
    }

    /**
     * @return bool
     */
    public function isAlive(): bool
    {
        return !$this->stream->isClosed();
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        $this->stream->close();
    }

    /**
     * 处理 HTTP 请求
     * @param array $reqInfo 请求信息
     * @return void
     * @throws ConnectionException
     */
    private function onRequest(array $reqInfo): void
    {
        if (!$this->httpServer) {
            return;
        }

        $req = new Request(
            $this,
            $reqInfo['query'],
            $reqInfo['request'],
            $reqInfo['cookies'],
            $reqInfo['files'],
            $reqInfo['server'],
            $reqInfo['content']
        );

        $response = $req->response();
        $response->withHeader('Server', 'ripple');

        // 半关闭检测
        $connHeader = $reqInfo['server']['HTTP_CONNECTION'] ?? '';
        $keepAlive = strtolower($connHeader) === 'keep-alive';

        if ($keepAlive) {
            $response->withHeader('Connection', 'keep-alive');
        } else {
            $response->withHeader('Connection', 'close');
            $this->stream->shutdownRead();
        }

        try {
            //            call_user_func($this->httpServer->onRequest, $req);
            Scheduler::resume($this->httpServer->acquireCoroutine(), $req)->throw();
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $req->respond($exception->getMessage(), [], 500);
            $this->reset();
        }
    }

    /**
     * 重置连接状态
     * @return void
     */
    private function reset(): void
    {
        $this->step             = self::STEP_INITIAL;
        $this->query            = [];
        $this->request          = [];
        $this->attributes       = [];
        $this->cookies          = [];
        $this->files            = [];
        $this->server           = [];
        $this->content          = '';
        $this->multipart        = null;
        $this->bodySize         = 0;
        $this->contentLength    = 0;
    }

    /**
     * 处理接收到的数据
     * @param string $content 接收的数据
     * @return array 解析出的请求列表
     * @throws FormatException 格式异常
     * @throws RuntimeException 运行时异常
     */
    private function processData(string $content): array
    {
        $list = [];

        $this->buf .= $content;

        if ($this->step === self::STEP_INITIAL) {
            $this->initialStep();
        }

        if ($this->step === self::STEP_CONTINUOUS) {
            $this->receiveBody();
        }

        if ($this->step === self::STEP_FILE_TRANSFER) {
            $this->processFileUpload();
        }

        if ($this->step === self::STEP_COMPLETE) {
            $list[] = $this->completeRequest();
            if ($this->buf !== '') {
                foreach ($this->processData('') as $item) {
                    $list[] = $item;
                }
            }
        }

        return $list;
    }

    /**
     * 处理初始步骤
     * @return void
     * @throws FormatException 格式异常
     * @throws RuntimeException 运行时异常
     */
    private function initialStep(): void
    {
        if ($headerEnd = strpos($this->buf, "\r\n\r\n")) {
            $buffer = $this->readBuffer();

            $this->step = self::STEP_CONTINUOUS;
            $header     = substr($buffer, 0, $headerEnd);
            $firstLine  = strtok($header, "\r\n");

            if (count($base = explode(' ', $firstLine)) !== 3) {
                throw new RuntimeException('Request head is not match: ' . $firstLine);
            }

            // 方法应该是大写字母
            if (!ctype_upper($base[0])) {
                throw new RuntimeException('Invalid HTTP method: ' . $base[0]);
            }

            // 版本格式
            if (!str_starts_with($base[2], 'HTTP/')) {
                throw new RuntimeException('Invalid HTTP version: ' . $base[2]);
            }

            $this->initParams($base);
            $this->parseHeaders();

            $this->method = Method::from($base[0]);

            $bodyStart = $headerEnd + 4;
            $remainingBuffer = substr($buffer, $bodyStart);

            if ($this->method->value === 'GET') {
                $body = '';
                $this->buf = $remainingBuffer;
            } else {
                $this->contentLength = intval($this->server['HTTP_CONTENT_LENGTH'] ?? 0);
                $this->contentType = $this->server['HTTP_CONTENT_TYPE'] ?? '';
                $bodyLength = max(0, $this->contentLength - $this->bodySize);
                $body = substr($remainingBuffer, 0, $bodyLength);
                $bodyLength = strlen($body);
                $this->buf = substr($remainingBuffer, $bodyLength);
                $this->bodySize += $bodyLength;
            }

            $this->processRequestBody($body);
        }
    }

    /**
     * 读取缓冲区数据
     * @param int $length 读取长度
     * @return string 读取的数据
     */
    private function readBuffer(int $length = 0): string
    {
        if ($length === 0) {
            $buffer   = $this->buf;
            $this->buf = '';
            return $buffer;
        }


        $buffer = substr($this->buf, 0, $length);
        $this->buf = substr($this->buf, $length);
        return $buffer;
    }

    /**
     * 初始化请求参数
     * @param array $base 基础参数
     * @return void
     */
    private function initParams(array $base): void
    {
        $urlExp = explode('?', $base[1]);
        $path   = parse_url($base[1], PHP_URL_PATH);

        if (isset($urlExp[1])) {
            $this->parseQuery($urlExp[1]);
        }

        $this->server['REQUEST_URI']     = $path;
        $this->server['REQUEST_METHOD']  = $base[0];
        $this->server['SERVER_PROTOCOL'] = $base[2];
    }

    /**
     * 解析查询字符串
     * @param string $queryStr 查询字符串
     * @return void
     */
    private function parseQuery(string $queryStr): void
    {
        parse_str($queryStr, $this->query);
    }

    /**
     * 解析HTTP头部
     * @return void
     */
    private function parseHeaders(): void
    {
        while ($line = strtok("\r\n")) {
            $param = explode(': ', $line, 2);
            if (count($param) >= 2) {
                $hdrName = strtoupper($param[0]);
                $hdrValue = $param[1];

                if (isset(self::COMMON_HEADERS[$hdrName])) {
                    $this->server[self::COMMON_HEADERS[$hdrName]] = $hdrValue;
                } else {
                    $this->server['HTTP_' . strtr($hdrName, '-', '_')] = $hdrValue;
                }
            }
        }
    }

    /**
     * 处理请求体
     * @param string $body 请求体
     * @return void
     * @throws FormatException 格式异常
     * @throws RuntimeException 运行时异常
     */
    private function processRequestBody(string $body): void
    {
        if (!$this->method->hasBody()) {
            $this->step = self::STEP_COMPLETE;
        } else {
            $this->processPostRequest($body);
        }
    }

    /**
     * 处理POST请求
     * @param string $body 请求体
     * @return void
     * @throws FormatException 格式异常
     * @throws RuntimeException 运行时异常
     */
    private function processPostRequest(string $body): void
    {
        if (str_contains($this->contentType, 'multipart/form-data')) {
            if (!preg_match('/boundary="?([^";]+)"?/i', $this->contentType, $matches)) {
                throw new RuntimeException('boundary is not set');
            }

            $boundary = $matches[1];
            $this->step = self::STEP_FILE_TRANSFER;
            if (!isset($this->multipart)) {
                $this->multipart = new Multipart($boundary);
            }

            foreach ($this->multipart->fill($body) as $name => $multipartResult) {
                if (is_string($multipartResult)) {
                    $this->request[$name] = $multipartResult;
                } elseif (is_array($multipartResult)) {
                    foreach ($multipartResult as $file) {
                        $this->files[$name][] = $file;
                    }
                }
            }
        } else {
            $this->content = $body;
        }

        $this->checkContentLength();
    }

    /**
     * 验证内容长度
     * @return void
     * @throws RuntimeException 运行时异常
     */
    private function checkContentLength(): void
    {
        if ($this->bodySize === $this->contentLength) {
            $this->step = self::STEP_COMPLETE;
        } elseif ($this->bodySize > $this->contentLength) {
            throw new RuntimeException('Content-Length is not match');
        }
    }

    /**
     * 处理连续传输
     * @return void
     * @throws RuntimeException 运行时异常
     */
    private function receiveBody(): void
    {
        if ($buffer = $this->readBuffer(max(0, $this->contentLength - $this->bodySize))) {
            $this->content .= $buffer;
            $this->bodySize += strlen($buffer);
            $this->checkContentLength();
        }
    }

    /**
     * 处理文件传输
     * @return void
     * @throws FormatException 格式异常
     * @throws RuntimeException 运行时异常
     */
    private function processFileUpload(): void
    {
        if ($buffer = $this->readBuffer(max(0, $this->contentLength - $this->bodySize))) {
            $this->bodySize += strlen($buffer);
            foreach ($this->multipart->fill($buffer) as $name => $multipartResult) {
                if (is_string($multipartResult)) {
                    $this->request[$name] = $multipartResult;
                } elseif (is_array($multipartResult)) {
                    foreach ($multipartResult as $file) {
                        $this->files[$name][] = $file;
                    }
                }
            }
            $this->checkContentLength();
        }
    }

    /**
     * 完成请求处理
     * @return array 请求数据
     */
    private function completeRequest(): array
    {
        $this->parseCookies();
        $this->parseRequestBody();
        $this->setUserIpInfo();

        $result = [
            'query'      => $this->query,
            'request'    => $this->request,
            'attributes' => $this->attributes,
            'cookies'    => $this->cookies,
            'files'      => $this->files,
            'server'     => $this->server,
            'content'    => $this->content,
        ];

        $this->reset();
        return $result;
    }

    /**
     * 解析 Cookie
     * @return void
     */
    private function parseCookies(): void
    {
        if (isset($this->server['HTTP_COOKIE'])) {
            parse_str(strtr($this->server['HTTP_COOKIE'], '; ', '& '), $this->cookies);
        }
    }

    /**
     * 解析请求体
     * @return void
     */
    private function parseRequestBody(): void
    {
        if ($this->method->value === 'POST') {
            if (str_contains($this->contentType, 'application/json')) {
                $this->request = array_merge($this->request, json_decode($this->content, true) ?? []);
            } else {
                parse_str($this->content, $reqParams);
                $this->request = array_merge($this->request, $reqParams);
            }
        }
    }

    /**
     * 设置用户IP信息
     * @return void
     */
    private function setUserIpInfo(): void
    {
        $this->server = array_merge($this->serverInfo, $this->server);
        if ($xfw = $this->server['HTTP_X_FORWARDED_PROTO'] ?? null) {
            $this->server['HTTPS'] = $xfw === 'https' ? 'on' : 'off';
        }
    }
}
