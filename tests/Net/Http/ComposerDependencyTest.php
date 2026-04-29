<?php declare(strict_types=1);

namespace Ripple\Tests\Net\Http;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;

final class ComposerDependencyTest extends TestCase
{
    public function testPsrHttpClientIsRuntimeDependency(): void
    {
        $composerJson = dirname(__DIR__, 3) . '/composer.json';
        $contents = file_get_contents($composerJson);

        self::assertIsString($contents);

        $package = json_decode($contents, true);

        self::assertIsArray($package);
        self::assertArrayHasKey('require', $package);
        self::assertArrayHasKey('psr/http-client', $package['require']);
    }
}
