<?php declare(strict_types=1);

namespace Ripple\Net\Http\Protocol;

use Ripple\Net\Http\Exception\ProtocolException;

use function count;
use function explode;
use function strtolower;
use function trim;

final class TransferEncoding
{
    /**
     * @param array $headers
     * @return bool
     */
    public static function isChunked(array $headers): bool
    {
        $codings = self::codings($headers);
        if ($codings === []) {
            return false;
        }

        foreach ($codings as $coding) {
            if ($coding !== 'chunked') {
                throw new ProtocolException("Unsupported transfer encoding: {$coding}");
            }
        }

        if (count($codings) > 1) {
            throw new ProtocolException('Invalid Transfer-Encoding: chunked applied more than once.');
        }

        return $codings[0] === 'chunked';
    }

    /**
     * @param array $headers
     * @return string[]
     */
    private static function codings(array $headers): array
    {
        $codings = [];
        foreach ($headers as $name => $values) {
            if (strtolower((string)$name) !== 'transfer-encoding') {
                continue;
            }

            foreach ((array)$values as $value) {
                foreach (explode(',', (string)$value) as $part) {
                    $coding = strtolower(trim(explode(';', $part, 2)[0]));
                    if ($coding === '') {
                        throw new ProtocolException('Invalid Transfer-Encoding header.');
                    }
                    $codings[] = $coding;
                }
            }
        }

        return $codings;
    }
}
