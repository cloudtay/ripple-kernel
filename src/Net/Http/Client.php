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

namespace Ripple\Net\Http;

use Ripple\Net\Http\Exception\TimeoutException;
use Ripple\Net\Http\Server\Request;
use Ripple\Net\Http\Server\Response;
use Ripple\Net\Http\Trait\ClientRequest;
use Ripple\Runtime\Scheduler;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Time;
use Throwable;
use InvalidArgumentException;

use function array_merge;
use function http_build_query;
use function is_array;
use function is_string;
use function ltrim;
use function microtime;
use function parse_str;
use function parse_url;
use function strlen;
use function strtoupper;
use function str_replace;
use function filesize;
use function rtrim;
use function sprintf;
use function fopen;
use function fwrite;
use function dirname;
use function is_dir;
use function mkdir;
use function is_callable;
use function is_file;
use function fclose;
use function is_resource;

use const PHP_URL_SCHEME;

class Client
{
    use ClientRequest;

    /**
     * @var array 客户端配置
     */
    private array $config = [
        'timeout' => 30.0,
        'connect_timeout' => 10.0,
        'headers' => [
            'User-Agent' => 'Ripple-Kernel/2.0'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    /**
     * @param array $config 客户端配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
        if (!empty($config['base_uri'])) {
            $this->config['base_uri'] = rtrim($config['base_uri'], '/');
        }
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function get(string $uri, array $options = []): Response
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function post(string $uri, array $options = []): Response
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function put(string $uri, array $options = []): Response
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function delete(string $uri, array $options = []): Response
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function patch(string $uri, array $options = []): Response
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function head(string $uri, array $options = []): Response
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function options(string $uri, array $options = []): Response
    {
        return $this->request('OPTIONS', $uri, $options);
    }

    /**
     * 发送HTTP请求
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return Response
     * @throws ConnectionException
     */
    public function request(string $method, string $uri, array $options = []): Response
    {
        $fullOptions = array_merge($this->config, $options);
        $timeout = $fullOptions['timeout'] ?? $this->config['timeout'];

        $startTime = microtime(true);
        $timeoutTimer = null;
        $owner = \Co\current();

        if ($timeout > 0) {
            $timeoutTimer = Time::afterFunc($timeout, function () use ($owner, $timeout) {
                Scheduler::tryThrow($owner, new TimeoutException('Request timeout', $timeout, 'request'));
            });
        }

        try {
            // 构建完整URL
            if (!parse_url($uri, PHP_URL_SCHEME) && !empty($fullOptions['base_uri'])) {
                $uri = sprintf('%s/%s', rtrim($fullOptions['base_uri'], '/'), ltrim($uri, '/'));
            }

            $urlParts = parse_url($uri);
            $scheme = $urlParts['scheme'] ?? 'http';
            $host = $urlParts['host'] ?? throw new InvalidArgumentException('Invalid URL: missing host');
            $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $path = $urlParts['path'] ?? '/';
            $query = $urlParts['query'] ?? '';

            $query = $this->buildQuery($query, $fullOptions);
            $requestUri = $query ? sprintf('%s?%s', $path, $query) : $path;

            [$content, $boundary] = $this->prepareRequestBody($fullOptions);

            $headers = $this->buildHeaders($fullOptions, $host, $content, $boundary);

            // 构建PSR风格SERVER数组
            $serverArray = [
                'REQUEST_METHOD' => strtoupper($method),
                'REQUEST_URI' => $requestUri,
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'HTTP_HOST' => $host,
            ];

            // 转换为SERVER格式的请求头
            foreach ($headers as $name => $value) {
                $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                $serverArray[$headerKey] = $value;
            }

            $request = new Request(
                conn: null,
                GET: [],
                POST: [],
                COOKIE: [],
                FILES: [],
                SERVER: $serverArray,
                CONTENT: $content
            );

            $stream = $this->newConn($scheme, $host, $port, $fullOptions);

            try {
                $response = $request($stream);
                if (isset($fullOptions['sink'])) {
                    $this->handleDownload($response, $fullOptions);
                }

                return $response;
            } finally {
                $stream->close();
            }

        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        } finally {
            // 清理定时器
            $timeoutTimer?->stop();
        }

        return $response;
    }

    /**
     * 构建查询字符串
     * @param string $existingQuery
     * @param array $options
     * @return string
     */
    private function buildQuery(string $existingQuery, array $options): string
    {
        $query = $existingQuery;

        if (isset($options['query'])) {
            $queryParams = [];

            if ($query) {
                parse_str($query, $queryParams);
            }

            if (is_array($options['query'])) {
                $queryParams = array_merge($queryParams, $options['query']);
            } elseif (is_string($options['query'])) {
                parse_str($options['query'], $newParams);
                $queryParams = array_merge($queryParams, $newParams);
            }

            $query = http_build_query($queryParams);
        }

        return $query;
    }

    /**
     * 构建请求头
     * @param array $options
     * @param string $host
     * @param mixed $content
     * @param string|null $boundary
     * @return array
     */
    private function buildHeaders(array $options, string $host, mixed $content, ?string $boundary = null): array
    {
        $headers = array_merge($this->config['headers'], $options['headers'] ?? []);

        $headers['Host'] = $host;
        $headers['Connection'] = 'close';

        if ($content !== null) {
            if (isset($options['json'])) {
                $headers['Content-Type'] = 'application/json';
            } elseif (isset($options['form_params'])) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            } elseif (isset($options['multipart']) && $boundary) {
                $headers['Content-Type'] = sprintf('multipart/form-data; boundary=%s', $boundary);
            }

            if (is_string($content)) {
                $headers['Content-Length'] = (string)strlen($content);
            } elseif ($content instanceof Stream) {
                $path = $content->getMetadata('uri');
                if (is_string($path) && $path !== '' && is_file($path)) {
                    $headers['Content-Length'] = (string)filesize($path);
                }
            }
        }

        return $headers;
    }

    /**
     * 创建连接
     * @param string $scheme 协议
     * @param string $host 主机
     * @param int $port 端口
     * @param array $options 选项
     * @return Stream
     * @throws ConnectionException
     */
    private function newConn(string $scheme, string $host, int $port, array $options): Stream
    {
        $timeout = $options['connect_timeout'] ?? $this->config['connect_timeout'];
        $connectString = sprintf('tcp://%s:%d', $host, $port);

        // 建立TCP连接
        $stream = Stream::connect($connectString, $timeout);

        // 对于HTTPS连接, 启用SSL加密
        if ($scheme === 'https') {
            $stream->enableSSL();
        }

        return $stream;
    }

    /**
     * 处理文件下载
     * @param Response $response 响应对象
     * @param array $options 请求选项
     * @throws InvalidArgumentException
     */
    private function handleDownload(Response $response, array $options): void
    {
        $sink = $options['sink'];
        $progress = $options['progress'] ?? null;

        if (is_string($sink)) {
            // 自动创建目录
            $dir = dirname($sink);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = fopen($sink, 'wb');
            if (!$file) {
                throw new InvalidArgumentException(sprintf('无法写入文件: %s', $sink));
            }
        } elseif (is_resource($sink)) {
            $file = $sink;
        } else {
            throw new InvalidArgumentException('sink必须是文件路径或资源');
        }

        try {
            $body = $response->body();
            $totalBytes = (int)($response->header('Content-Length') ?? 0);
            $downloadedBytes = 0;

            if (is_string($body)) {
                $downloadedBytes = strlen($body);
                fwrite($file, $body);
            } else {
                // 简化处理, 后续可扩展流式下载
                $data = (string)$body;
                $downloadedBytes = strlen($data);
                fwrite($file, $data);
            }

            if ($progress && is_callable($progress)) {
                $progress($totalBytes, $downloadedBytes, 0, 0);
            }

        } finally {
            if (is_string($sink)) {
                fclose($file);
            }
        }
    }

    /**
     * @return array
     */
    public function config(): array
    {
        return $this->config;
    }
}
