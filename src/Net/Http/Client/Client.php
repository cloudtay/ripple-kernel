<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use Ripple\Stream\Exception\ConnectionException;

use function http_build_query;
use function json_encode;
use function strtoupper;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class Client implements ClientInterface
{
    /**
     * @var Connector|Closure
     */
    private Connector|Closure $connector;

    /**
     * @var RequestSerializer|mixed
     */
    private RequestSerializer $serializer;

    /**
     * @param array $config
     */
    public function __construct(private array $config = [])
    {
        $this->connector = $config['connector'] ?? new Connector();
        $this->serializer = $config['serializer'] ?? new RequestSerializer();
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (isset($this->config['sender'])) {
            return ($this->config['sender'])($request);
        }

        $stream = $this->connector instanceof Connector
            ? $this->connector->connect($request->getUri(), (float)($this->config['connect_timeout'] ?? 10.0))
            : ($this->connector)($request->getUri(), $this->config);

        try {
            $stream->writeAll($this->serializer->serialize($request));
            $parser = new ResponseParser();

            while (true) {
                $responses = $parser->push($stream->read(8192));
                if ($responses !== []) {
                    return $responses[0];
                }
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? '';

        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($options['form_params'])) {
            $body = http_build_query($options['form_params']);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return $this->sendRequest(new Request(
            method: strtoupper($method),
            uri: new Uri($uri),
            headers: $headers,
            body: BodyStream::fromString((string)$body),
        ));
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function get(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function post(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function put(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function patch(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    public function delete(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $options);
    }
}
