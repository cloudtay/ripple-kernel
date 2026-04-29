<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ripple\Net\Http\Connection;
use Ripple\Net\Http\Response;
use Ripple\Stream;

use function fopen;

final class ServerConnectionPsrTest extends TestCase
{
    public function testConnectionDoesNotRequireServerAndDispatchesPsrRequest(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);

        $seen = null;
        $connection = new Connection(
            new Stream($resource),
            function (ServerRequestInterface $request) use (&$seen): Response {
                $seen = $request;
                return Response::text('ok');
            }
        );

        foreach ($connection->fillForTest("GET /hello HTTP/1.1\r\nHost: example.com\r\n\r\n") as $response) {
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertInstanceOf(ServerRequestInterface::class, $seen);
    }
}
