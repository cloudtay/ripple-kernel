<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ripple\Net\Http\Connection;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Server;
use Ripple\Stream;

use function fopen;

final class ConnectionDecouplingTest extends TestCase
{
    public function testRequestExposesStreamWithoutServerConnectionDependency(): void
    {
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);

        $stream = new Stream($handle);
        $request = new Request(SERVER: ['REQUEST_URI' => '/'], stream: $stream);

        self::assertSame($stream, $request->stream());
        self::assertFalse((new ReflectionClass($request))->hasProperty('conn'));
    }

    public function testConnectionConstructorDoesNotRequireServer(): void
    {
        $constructor = (new ReflectionClass(Connection::class))->getConstructor();
        self::assertNotNull($constructor);

        $parameterTypes = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameterTypes[] = $type ? (string) $type : '';
        }

        self::assertNotContains(Server::class, $parameterTypes);
    }
}
