<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Client\Client;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Response;
use Ripple\Net\Http\Uri;

use function strlen;

final class ClientTest extends TestCase
{
    public function testSendRequestSerializesRequestAndReturnsParsedResponse(): void
    {
        $client = new Client([
            'connector' => static fn (): object => new class () {
                public string $written = '';
                public function writeAll(string $bytes): int
                {
                    $this->written .= $bytes;
                    return strlen($bytes);
                }
                public function read(int $length): string
                {
                    return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
                }
                public function close(): void
                {
                }
            },
        ]);

        $response = $client->sendRequest(new Request(method: 'GET', uri: new Uri('http://example.com/')));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string)$response->getBody());
    }

    public function testConvenienceGetBuildsRequest(): void
    {
        $client = new Client([
            'sender' => static fn (Request $request): Response => Response::text($request->getMethod() . ' ' . $request->getRequestTarget()),
        ]);

        $response = $client->get('http://example.com/path?q=1');

        self::assertSame('GET /path?q=1', (string)$response->getBody());
    }
}
