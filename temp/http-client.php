<?php declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http;
use Ripple\Net\Http\BodyStream;

include __DIR__ . '/../vendor/autoload.php';

$baseUrl = \rtrim((string)(\getenv('HTTP_DEBUG_BASE_URL') ?: 'https://httpbin.org'), '/');
$client = Http::client([
    'connect_timeout' => 10.0,
    'write_timeout' => 10.0,
    'read_timeout' => 20.0,
    'request_timeout' => 30.0,
    'max_header_bytes' => 65536,
    'max_body_bytes' => 4 * 1024 * 1024,
    'decode_content' => true,
    'upload_chunk_size' => 4,
]);

echo "Ripple HTTP client debug examples\n";
echo "Base URL: {$baseUrl}\n";
echo "Override with: HTTP_DEBUG_BASE_URL=https://httpbingo.org php temp/http-client.php\n\n";

\run('GET with query string', function () use ($client, $baseUrl): void {
    $response = $client->get($baseUrl . '/get?name=ripple&debug=1', [
        'headers' => [
            'X-Debug-Client' => 'ripple-kernel',
        ],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'url=' . ($data['url'] ?? '') . "\n";
    echo 'header.x-debug-client=' . ($data['headers']['X-Debug-Client'] ?? '') . "\n";
});

\run('Generic request() with custom method', function () use ($client, $baseUrl): void {
    $response = $client->request('GET', $baseUrl . '/anything/generic', [
        'headers' => [
            'X-Request-Api' => 'request-method',
        ],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'url=' . ($data['url'] ?? '') . "\n";
    echo 'header.x-request-api=' . ($data['headers']['X-Request-Api'] ?? '') . "\n";
});

\run('POST json', function () use ($client, $baseUrl): void {
    $response = $client->post($baseUrl . '/post', [
        'json' => [
            'name' => 'ripple',
            'enabled' => true,
            'count' => 3,
        ],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'json.name=' . ($data['json']['name'] ?? '') . "\n";
    echo 'content-type=' . ($data['headers']['Content-Type'] ?? '') . "\n";
});

\run('POST form_params', function () use ($client, $baseUrl): void {
    $response = $client->post($baseUrl . '/post', [
        'form_params' => [
            'username' => 'alice',
            'role' => 'admin',
        ],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'form.username=' . ($data['form']['username'] ?? '') . "\n";
});

\run('POST raw string body', function () use ($client, $baseUrl): void {
    $response = $client->post($baseUrl . '/post', [
        'headers' => [
            'Content-Type' => 'text/plain',
        ],
        'body' => 'plain request body',
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'data=' . ($data['data'] ?? '') . "\n";
});

\run('POST resource body', function () use ($client, $baseUrl): void {
    $resource = \fopen('php://temp', 'w+');
    \fwrite($resource, 'resource request body');
    \rewind($resource);

    try {
        $response = $client->post($baseUrl . '/post', [
            'headers' => [
                'Content-Type' => 'text/plain',
            ],
            'body' => $resource,
        ]);
    } finally {
        \fclose($resource);
    }

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'data=' . ($data['data'] ?? '') . "\n";
});

\run('POST PSR-7 stream body', function () use ($client, $baseUrl): void {
    $response = $client->post($baseUrl . '/post', [
        'headers' => [
            'Content-Type' => 'text/plain',
        ],
        'body' => BodyStream::fromString('psr stream request body'),
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'data=' . ($data['data'] ?? '') . "\n";
});

\run('POST multipart', function () use ($client, $baseUrl): void {
    $response = $client->post($baseUrl . '/post', [
        'multipart' => [
            [
                'name' => 'field',
                'contents' => 'value',
            ],
            [
                'name' => 'upload',
                'filename' => 'example.txt',
                'contents' => 'file contents from multipart',
            ],
        ],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'form.field=' . ($data['form']['field'] ?? '') . "\n";
    echo 'files.upload=' . ($data['files']['upload'] ?? '') . "\n";
});

\run('PUT json', function () use ($client, $baseUrl): void {
    $response = $client->put($baseUrl . '/put', [
        'json' => ['operation' => 'replace'],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'json.operation=' . ($data['json']['operation'] ?? '') . "\n";
});

\run('PATCH json', function () use ($client, $baseUrl): void {
    $response = $client->patch($baseUrl . '/patch', [
        'json' => ['operation' => 'patch'],
    ]);

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'json.operation=' . ($data['json']['operation'] ?? '') . "\n";
});

\run('DELETE', function () use ($client, $baseUrl): void {
    $response = $client->delete($baseUrl . '/delete');

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'url=' . ($data['url'] ?? '') . "\n";
});

\run('Content-Encoding gzip decode enabled', function () use ($client, $baseUrl): void {
    $response = $client->get($baseUrl . '/gzip');

    \printResponse($response);
    $data = \jsonBody($response);
    echo 'gzipped=' . \boolText((bool)($data['gzipped'] ?? false)) . "\n";
    echo 'content-encoding-after-decode=' . ($response->getHeaderLine('Content-Encoding') ?: '(removed)') . "\n";
});

\run('Content-Encoding gzip decode disabled', function () use ($baseUrl): void {
    $rawClient = Http::client([
        'decode_content' => false,
        'request_timeout' => 30.0,
    ]);

    $response = $rawClient->get($baseUrl . '/gzip');

    \printResponse($response);
    echo 'content-encoding=' . ($response->getHeaderLine('Content-Encoding') ?: '(none)') . "\n";
    echo 'raw-bytes=' . \strlen((string)$response->getBody()) . "\n";
});

\run('Transfer-Encoding chunked response', function () use ($client, $baseUrl): void {
    $response = $client->get($baseUrl . '/stream/3');

    \printResponse($response);
    echo 'transfer-encoding-after-read=' . ($response->getHeaderLine('Transfer-Encoding') ?: '(removed or absent)') . "\n";
    echo 'body-preview=' . \preview((string)$response->getBody()) . "\n";
});

\run('Stream response body', function () use ($client, $baseUrl): void {
    $response = $client->get($baseUrl . '/stream/5', [
        'stream' => true,
    ]);

    \printResponse($response);
    $body = $response->getBody();
    echo 'stream-seekable=' . \boolText($body->isSeekable()) . "\n";
    echo 'first-read=' . \preview($body->read(80)) . "\n";
    echo 'remaining-bytes=' . \strlen($body->getContents()) . "\n";
    $body->close();
});

\run('Download to sink file with progress', function () use ($client, $baseUrl): void {
    $sink = __DIR__ . '/http-client-download.bin';
    $progressEvents = [];

    $response = $client->get($baseUrl . '/bytes/128', [
        'sink' => $sink,
        'progress' => static function (int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded) use (&$progressEvents): void {
            $progressEvents[] = [$downloadTotal, $downloaded, $uploadTotal, $uploaded];
        },
    ]);

    \printResponse($response);
    echo 'sink=' . $sink . "\n";
    echo 'downloaded-bytes=' . \filesize($sink) . "\n";
    echo 'progress-events=' . \count($progressEvents) . "\n";
    echo 'last-progress=' . \json_encode($progressEvents[\count($progressEvents) - 1] ?? [], \JSON_UNESCAPED_SLASHES) . "\n";
});

\run('Response size limit failure', function () use ($baseUrl): void {
    $limitedClient = Http::client([
        'max_body_bytes' => 8,
        'request_timeout' => 30.0,
    ]);

    $limitedClient->get($baseUrl . '/bytes/64');
});

\run('Request timeout failure', function () use ($baseUrl): void {
    $timeoutClient = Http::client([
        'request_timeout' => 0.5,
    ]);

    \set_error_handler(static fn (): bool => true);
    try {
        $timeoutClient->get($baseUrl . '/delay/2');
    } finally {
        \restore_error_handler();
    }
});

/**
 * @param string $title
 * @param callable():void $callback
 * @return void
 */
function run(string $title, callable $callback): void
{
    echo "== {$title} ==\n";
    try {
        $callback();
    } catch (Throwable $exception) {
        echo 'exception=' . $exception::class . ': ' . $exception->getMessage() . "\n";
    }
    echo "\n";
}

function printResponse(ResponseInterface $response): void
{
    echo 'status=' . $response->getStatusCode() . "\n";
    echo 'protocol=' . $response->getProtocolVersion() . "\n";
    echo 'content-type=' . ($response->getHeaderLine('Content-Type') ?: '(none)') . "\n";
    echo 'content-length=' . ($response->getHeaderLine('Content-Length') ?: '(none)') . "\n";
}

/**
 * @return array<string,mixed>
 */
function jsonBody(ResponseInterface $response): array
{
    $data = \json_decode((string)$response->getBody(), true);
    return \is_array($data) ? $data : [];
}

function preview(string $value, int $limit = 120): string
{
    $value = \str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
    return \strlen($value) > $limit ? \substr($value, 0, $limit) . '...' : $value;
}

function boolText(bool $value): string
{
    return $value ? 'true' : 'false';
}
