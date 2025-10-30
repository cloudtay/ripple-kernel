<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Net\Http;
use Ripple\Net\Http\Request;
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
                $request->respond('HTML template not found', ['Content-Type' => 'text/plain'], 404);
                return;
            }

            $html = \file_get_contents($htmlPath);
            $request->respond($html, ['Content-Type' => 'text/html; charset=utf-8']);
            return;
        }

        $request->respond('Not Found', ['Content-Type' => 'text/plain'], 404);
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
    $request->response()
        ->withHeader('Content-Type', 'text/event-stream')
        ->withHeader('Cache-Control', 'no-cache')
        ->withHeader('Connection', 'keep-alive')
        ->withHeader('X-Accel-Buffering', 'no')
        ->withBody(function () {
            $eventId = 0;

            for ($i = 1; $i <= 10; $i++) {
                $eventId++;
                $time = \date('H:i:s');
                $data = \json_encode([
                    'id' => $eventId,
                    'time' => $time,
                    'message' => "Event #$i"
                ]);

                yield "id: $eventId\n";
                yield "data: $data\n";
                yield "\n";

                Co\sleep(1);
            }
        })
        ->closeAfter()
        ->send();
}

Runtime::run(static fn () => \main());
