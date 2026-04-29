<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\Response;

final class ResponsePsrTest extends TestCase
{
    public function testResponseImplementsPsrResponseInterface(): void
    {
        $response = new Response(201, ['X-Test' => 'yes'], 'created', 'Created');

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('yes', $response->getHeaderLine('x-test'));
        self::assertSame('created', (string)$response->getBody());
    }

    public function testStatusWithMethodIsImmutable(): void
    {
        $response = new Response();
        $changed = $response->withStatus(404, 'Not Found');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(404, $changed->getStatusCode());
        self::assertSame('Not Found', $changed->getReasonPhrase());
    }

    public function testFactoriesPreserveContentLength(): void
    {
        $response = Response::text('ok', 202);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('2', $response->getHeaderLine('Content-Length'));
    }
}
