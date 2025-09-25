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

use Ripple\Stream\Exception\ConnectionException;

use function array_merge;
use function is_string;
use function json_encode;

/**
 * 请求实体
 */
class Request
{
    /*** @var array */
    public readonly array $REQUEST;

    /*** @var Response */
    protected Response $response;

    /**
     * 构造请求实体
     * @param Connection $conn
     * @param array $GET
     * @param array $POST
     * @param array $COOKIE
     * @param array $FILES
     * @param array $SERVER
     * @param mixed|null $CONTENT
     */
    public function __construct(
        public readonly Connection $conn,
        public readonly array      $GET = [],
        public readonly array      $POST = [],
        public readonly array      $COOKIE = [],
        public readonly array      $FILES = [],
        public readonly array      $SERVER = [],
        public readonly mixed      $CONTENT = null,
    ) {
        $this->REQUEST = array_merge($this->GET, $this->POST);
    }

    /**
     * 响应文本
     * @param mixed $content
     * @param array $withHeaders
     * @param int $statusCode
     * @return void
     * @throws ConnectionException
     */
    public function respond(mixed $content = null, array $withHeaders = [], int $statusCode = 200): void
    {
        $response = $this->response();

        if ($content) {
            $response->withBody($content);
        }

        if ($statusCode) {
            $response->setStatusCode($statusCode);
        }

        foreach ($withHeaders as $name => $value) {
            $response->withHeader($name, $value);
        }

        $response($this->conn->stream);
    }

    /**
     * 响应JSON
     * @param mixed $content
     * @param array $withHeaders
     * @param int $statusCode
     * @return void
     * @throws ConnectionException
     */
    public function respondJson(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            is_string($content) ? $content : json_encode($content),
            array_merge(['Content-Type' => 'application/json'], $withHeaders),
            $statusCode
        );
    }

    /**
     * 响应文本
     * @param mixed $content
     * @param array $withHeaders
     * @param int $statusCode
     * @return void
     * @throws ConnectionException
     */
    public function respondText(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/plain'], $withHeaders),
            $statusCode
        );
    }

    /**
     * 响应HTML页面
     * @param mixed $content
     * @param array $withHeaders
     * @param int $statusCode
     * @return void
     * @throws ConnectionException
     */
    public function respondHtml(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/html'], $withHeaders),
            $statusCode
        );
    }

    /**
     * 获取响应单例
     * @return Response
     */
    public function response(): Response
    {
        if (!isset($this->response)) {
            $this->response = new Response();
        }

        return $this->response;
    }
}
