<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use Ripple\Net\Http\Client\ResponseDecoder;
use Ripple\Net\Http\Exception\ProtocolException;
use Ripple\Net\Http\Response;

use function gzdeflate;
use function gzencode;

final class ResponseDecoderTest extends TestCase
{
    public function testDecodesGzipResponse(): void
    {
        $response = new Response(200, [
            'Content-Encoding' => 'gzip',
            'Content-Length' => '0',
        ], gzencode('hello'));

        $decoded = (new ResponseDecoder())->decode($response);

        self::assertSame('hello', (string)$decoded->getBody());
        self::assertFalse($decoded->hasHeader('Content-Encoding'));
        self::assertSame('5', $decoded->getHeaderLine('Content-Length'));
    }

    public function testDecodesDeflateResponse(): void
    {
        $response = new Response(200, [
            'Content-Encoding' => 'deflate',
        ], gzdeflate('hello'));

        $decoded = (new ResponseDecoder())->decode($response);

        self::assertSame('hello', (string)$decoded->getBody());
        self::assertFalse($decoded->hasHeader('Content-Encoding'));
        self::assertSame('5', $decoded->getHeaderLine('Content-Length'));
    }

    public function testInvalidGzipThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);

        $response = new Response(200, ['Content-Encoding' => 'gzip'], 'not-gzip');
        (new ResponseDecoder())->decode($response);
    }
}
