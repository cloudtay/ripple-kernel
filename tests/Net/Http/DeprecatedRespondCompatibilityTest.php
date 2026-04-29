<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Connection;
use Ripple\Stream;

use function fopen;

final class DeprecatedRespondCompatibilityTest extends TestCase
{
    public function testDeprecatedRespondJsonStillEmitsResponse(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        $connection = new Connection($stream, function ($request): void {
            $request->respondJson(['ok' => true], [], 201);
        });

        $responses = $connection->fillForTest("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");

        self::assertCount(1, $responses);
        self::assertSame(201, $responses[0]->getStatusCode());
        self::assertSame('application/json', $responses[0]->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', (string)$responses[0]->getBody());
    }
}
