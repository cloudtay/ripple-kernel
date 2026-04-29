<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Psr\Http\Message\UriInterface;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;

use function sprintf;

final class Connector
{
    /**
     * @param UriInterface $uri
     * @param float $timeout
     * @return Stream
     * @throws ConnectionException
     */
    public function connect(UriInterface $uri, float $timeout = 10.0): Stream
    {
        $scheme = $uri->getScheme() ?: 'http';
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        $stream = Stream::connect(sprintf('tcp://%s:%d', $host, $port), $timeout);
        if ($scheme === 'https') {
            $stream->enableSSL();
        }

        return $stream;
    }
}
