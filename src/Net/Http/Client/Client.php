<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Exception\NetworkException;
use Ripple\Net\Http\Exception\TimeoutException;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Request;
use Ripple\Net\Http\Uri;
use Ripple\Runtime\Exception\CoroutineStateException;
use Ripple\Runtime\Scheduler;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Time;

use function http_build_query;
use function json_encode;
use function method_exists;
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
     * @var ClientOptions
     */
    private ClientOptions $options;

    /**
     * @var ResponseDecoder|mixed
     */
    private ResponseDecoder $decoder;

    /**
     * @param array $config
     */
    public function __construct(private array $config = [])
    {
        $this->connector = $config['connector'] ?? new Connector();
        $this->serializer = $config['serializer'] ?? new RequestSerializer();
        $this->options = ClientOptions::fromArray($config);
        $this->decoder = $config['decoder'] ?? new ResponseDecoder();
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
            ? $this->connector->connect($request->getUri(), $this->options->connectTimeout())
            : ($this->connector)($request->getUri(), $this->config);

        try {
            $this->assertRequestNotExpired($request);
            $stream->writeAll($this->serializer->serialize($request), $this->options->writeTimeout());
            $this->assertRequestNotExpired($request);
            $parser = new ResponseParser(
                method: $request->getMethod(),
                maxHeaderBytes: $this->options->maxHeaderBytes(),
                maxBodyBytes: $this->options->maxBodyBytes(),
            );

            while (true) {
                $chunk = $this->readChunk($stream, $request);
                if ($chunk === '' && $stream->eof()) {
                    $responses = $parser->finish();
                } else {
                    $responses = $parser->push($chunk);
                }
                if ($responses !== []) {
                    return $this->options->decodeContent() ? $this->decoder->decode($responses[0]) : $responses[0];
                }
            }
        } catch (ConnectionException $exception) {
            throw new NetworkException($exception->getMessage(), $request, 0, $exception);
        } finally {
            $stream->close();
        }
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws ConnectionException
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
     * @throws ConnectionException
     */
    public function get(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function post(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function put(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function patch(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function delete(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param RequestInterface $request
     * @return void
     */
    private function assertRequestNotExpired(RequestInterface $request): void
    {
        if ($this->options->hasExpired()) {
            throw new TimeoutException('Request timeout exceeded.', $request);
        }
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @return string
     */
    private function readChunk(object $stream, RequestInterface $request): string
    {
        $this->assertRequestNotExpired($request);

        if (!method_exists($stream, 'watchRead')) {
            return $stream->read(8192);
        }

        $owner = \Co\current();
        $stream->watchRead(static function () use ($owner): void {
            Scheduler::resume($owner)->unwrap(CoroutineStateException::class);
        });

        $timer = Time::afterFunc($this->options->readTimeout(), function () use ($owner, $request): void {
            Scheduler::throw($owner, new TimeoutException('Read timeout exceeded.', $request))->unwrap(CoroutineStateException::class);
        });

        try {
            $owner->suspend();
            $this->assertRequestNotExpired($request);
            return $stream->read(8192);
        } finally {
            $timer->stop();
            if (method_exists($stream, 'unwatchRead')) {
                $stream->unwatchRead();
            }
        }
    }
}
