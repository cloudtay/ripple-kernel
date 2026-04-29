<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Net\Http;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Response;
use Ripple\Runtime;
use Ripple\Stream\Exception\ConnectionException;

function main(): int
{
    $server = Http::server('http://0.0.0.0:8000');

    $server->onRequest = function (Request $request) {
        $uri = $request->SERVER['REQUEST_URI'];

        if ($uri === '/events') {
            \respondSSE($request);
            return;
        }

        if ($uri === '/') {
            $htmlPath = __DIR__ . '/11-http-sse.html';
            if (!\is_file($htmlPath)) {
                return Response::text('HTML template not found', 404);
            }

            $html = \file_get_contents($htmlPath);
            return Response::html($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return Response::text('Not Found', 404);
    };

    echo "SSE server started: http://127.0.0.1:8000\n";
    $server->listen();

    return 0;
}

/**
 * @param Request $request
 * @return void
 * @throws ConnectionException
 */
function respondSSE(Request $request): void
{
    // SSE keeps the connection open and writes to the stream directly.
    $stream = $request->stream();
    $stream->writeAll(
        "HTTP/1.1 200 OK\r\n" .
        "Content-Type: text/event-stream\r\n" .
        "Cache-Control: no-cache\r\n" .
        "Connection: close\r\n" .
        "X-Accel-Buffering: no\r\n" .
        "\r\n"
    );

    for ($i = 1; $i <= 10; $i++) {
        $time = \date('H:i:s');
        $data = \json_encode([
            'id' => $i,
            'time' => $time,
            'message' => "Event #$i"
        ]);

        $stream->writeAll("id: $i\n");
        $stream->writeAll("data: $data\n");
        $stream->writeAll("\n");

        Co\sleep(1);
    }

    $stream->close();
}

Runtime::run(static fn () => \main());
