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

namespace Ripple\Net\Http\Parser;

use Ripple\Net\Exception\FormatException;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Enum\Method;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use RuntimeException;

use function array_merge;
use function count;
use function ctype_upper;
use function explode;
use function intval;
use function json_decode;
use function max;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtok;
use function strtoupper;
use function strtr;
use function substr;
use function trim;

use const PHP_URL_PATH;

final class RequestParser
{
    /**
     *
     */
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
     *
     */
    private const STEP_INITIAL = 0;
    private const STEP_CONTINUOUS = 1;
    private const STEP_COMPLETE = 2;
    private const STEP_FILE_TRANSFER = 3;

    /**
     * @var int
     */
    private int $step;

    /**
     * @var array
     */
    private array $get;

    /**
     * @var array
     */
    private array $post;

    /**
     * @var array
     */
    private array $attributes;

    /**
     * @var array
     */
    private array $cookies;

    /**
     * @var array
     */
    private array $files;

    /**
     * @var string
     */
    private string $content;

    /**
     * @var string
     */
    private string $buf = '';

    /**
     * @var FormDataParser|null
     */
    private FormDataParser|null $multipart;

    /**
     * @var int
     */
    private int $bodySize;

    /**
     * @var int
     */
    private int $contentLength;

    /**
     * @var string
     */
    private string $contentType;

    /**
     * @var Method
     */
    private Method $method;

    /**
     * @var array
     */
    private array $meta;

    /**
     * @param array $alwaysMeta
     */
    public function __construct(private readonly array $alwaysMeta = [])
    {
        $this->reset();
    }

    /**
     * @return list<Request>
     * @throws FormatException
     * @throws RuntimeException
     */
    public function push(string $content): array
    {
        return $this->fill($content);
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        $this->step = self::STEP_INITIAL;
        $this->get = [];
        $this->post = [];
        $this->attributes = [];
        $this->cookies = [];
        $this->files = [];
        $this->meta = $this->alwaysMeta;
        $this->content = '';
        $this->multipart = null;
        $this->bodySize = 0;
        $this->contentLength = 0;
        $this->contentType = '';
    }

    /**
     * @return list<Request>
     * @throws FormatException
     * @throws RuntimeException
     */
    private function fill(string $content): array
    {
        $reqs = [];
        $this->buf .= $content;

        if ($this->step === self::STEP_INITIAL) {
            $this->initialStep();
        }

        if ($this->step === self::STEP_CONTINUOUS) {
            $this->receiveBody();
        }

        if ($this->step === self::STEP_FILE_TRANSFER) {
            $this->processFormData();
        }

        if ($this->step === self::STEP_COMPLETE) {
            $reqs[] = $this->completeRequest();

            if ($this->buf !== '') {
                foreach ($this->fill('') as $item) {
                    $reqs[] = $item;
                }
            }
        }

        return $reqs;
    }

    /**
     * @throws FormatException
     * @throws RuntimeException
     */
    private function initialStep(): void
    {
        if ($headerEnd = strpos($this->buf, "\r\n\r\n")) {
            $buffer = $this->readBuffer();

            $this->step = self::STEP_CONTINUOUS;
            $header = substr($buffer, 0, $headerEnd);
            $firstLine = strtok($header, "\r\n");

            if (count($base = explode(' ', $firstLine)) !== 3) {
                throw new RuntimeException('Request head is not match: ' . $firstLine);
            }

            if (!ctype_upper($base[0])) {
                throw new RuntimeException('Invalid HTTP method: ' . $base[0]);
            }

            if (!str_starts_with($base[2], 'HTTP/')) {
                throw new RuntimeException('Invalid HTTP version: ' . $base[2]);
            }

            $this->initParams($base);
            $this->parseHeaders();

            $this->method = Method::from($base[0]);
            $this->buf = substr($buffer, $headerEnd + 4);

            if (!$this->method->hasBody()) {
                $this->step = self::STEP_COMPLETE;
                return;
            }

            $this->contentLength = intval($this->meta['HTTP_CONTENT_LENGTH'] ?? 0);
            $this->contentType = $this->meta['HTTP_CONTENT_TYPE'] ?? '';

            if (str_contains($this->contentType, 'multipart/form-data')) {
                if (!preg_match('/boundary="?([^";]+)"?/i', $this->contentType, $matches)) {
                    throw new RuntimeException('boundary is not set');
                }

                $this->step = self::STEP_FILE_TRANSFER;
                if (!isset($this->multipart)) {
                    $this->multipart = new FormDataParser($matches[1]);
                }
            } else {
                $this->step = self::STEP_CONTINUOUS;
            }
        }
    }

    /**
     * @param int $length
     * @return string
     */
    private function readBuffer(int $length = 0): string
    {
        if ($length === 0) {
            $buffer = $this->buf;
            $this->buf = '';
            return $buffer;
        }

        $buffer = substr($this->buf, 0, $length);
        $this->buf = substr($this->buf, $length);
        return $buffer;
    }

    /**
     * @param array $base
     * @return void
     */
    private function initParams(array $base): void
    {
        $urlExp = explode('?', $base[1]);
        $path = parse_url($base[1], PHP_URL_PATH);

        if (isset($urlExp[1])) {
            $this->parseQuery($urlExp[1]);
        }

        $this->meta['REQUEST_URI'] = $path;
        $this->meta['REQUEST_TARGET'] = $base[1];
        $this->meta['REQUEST_METHOD'] = $base[0];
        $this->meta['SERVER_PROTOCOL'] = $base[2];
    }

    /**
     * @param string $queryStr
     * @return void
     */
    private function parseQuery(string $queryStr): void
    {
        parse_str($queryStr, $this->get);
    }

    /**
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
                    $this->meta[self::COMMON_HEADERS[$hdrName]] = $hdrValue;
                } else {
                    $this->meta['HTTP_' . strtr($hdrName, '-', '_')] = $hdrValue;
                }
            }
        }
    }

    /**
     * @return void
     */
    private function receiveBody(): void
    {
        if ($buffer = $this->readBuffer(max(0, $this->contentLength - $this->bodySize))) {
            $this->content .= $buffer;
            $this->bodySize += strlen($buffer);
        }

        $this->checkContentLength();
    }

    /**
     * @return void
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
     * @throws FormatException
     * @throws RuntimeException
     */
    private function processFormData(): void
    {
        if ($buffer = $this->readBuffer(max(0, $this->contentLength - $this->bodySize))) {
            $this->bodySize += strlen($buffer);
            foreach ($this->multipart->fill($buffer) as $multipartResult) {
                foreach ($multipartResult as $formItem) {
                    $name = $formItem['name'];
                    $isArray = str_ends_with($name, '[]');
                    $isFile = $formItem['isFile'];

                    if ($isArray) {
                        $name = substr($name, 0, -2);
                        if ($isFile) {
                            $this->files[$name][] = $formItem;
                        } else {
                            $this->post[$name][] = $formItem['value'];
                        }
                    } else {
                        if ($isFile) {
                            $this->files[$name] = $formItem;
                        } else {
                            $this->post[$name] = $formItem['value'];
                        }
                    }
                }
            }
        }

        $this->checkContentLength();
    }

    /**
     * @return Request
     */
    private function completeRequest(): Request
    {
        $this->parseCookies();
        $this->parseRequestBody();
        $this->parseXfp();

        $headers = [];
        foreach ($this->meta as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', substr($key, 5));
            $headers[$name] = $value;
        }

        $host = $this->meta['HTTP_HOST'] ?? 'localhost';
        $scheme = ($this->meta['HTTPS'] ?? 'off') === 'on' ? 'https' : 'http';
        $target = $this->meta['REQUEST_TARGET'] ?? ($this->meta['REQUEST_URI'] ?? '/');

        $result = new Request(
            method: $this->method->value,
            uri: new Uri("{$scheme}://{$host}{$target}"),
            headers: $headers,
            body: BodyStream::fromString((string)$this->content),
            serverParams: $this->meta,
            queryParams: $this->get,
            parsedBody: $this->post,
            cookieParams: $this->cookies,
            uploadedFiles: $this->files,
            attributes: $this->attributes,
            requestTarget: $target,
        );

        $this->reset();
        return $result;
    }

    /**
     * @return void
     */
    private function parseCookies(): void
    {
        $this->cookies = [];
        if ($headerCookie = $this->meta['HTTP_COOKIE'] ?? '') {
            $pairs = explode(';', $headerCookie);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $name = trim($name);
                $value = trim($value);

                if ($name === '') {
                    continue;
                }

                $this->cookies[rawurldecode($name)] = rawurldecode($value);
            }
        }
    }

    /**
     * @return void
     */
    private function parseRequestBody(): void
    {
        if ($this->method->value === 'POST') {
            if (str_contains($this->contentType, 'application/json')) {
                $this->post = array_merge($this->post, json_decode($this->content ?? "", true) ?? []);
            } elseif ($this->contentType === 'application/x-www-form-urlencoded') {
                parse_str($this->content, $this->post);
            }
        }
    }

    /**
     * @return void
     */
    private function parseXfp(): void
    {
        if ($xfw = $this->meta['HTTP_X_FORWARDED_PROTO'] ?? null) {
            $this->meta['HTTPS'] = $xfw === 'https' ? 'on' : 'off';
        }
    }
}
