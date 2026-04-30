<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Ripple\Net\Http\BodyStream;
use Ripple\Net\Http\Client\Body\NetworkResponseStream;
use Ripple\Net\Http\Exception\NetworkException;
use Ripple\Net\Http\Exception\ProtocolException;
use Ripple\Net\Http\Exception\TimeoutException;
use Ripple\Net\Http\Protocol\RequestSerializer;
use Ripple\Net\Http\Protocol\ResponseParser;
use Ripple\Net\Http\Protocol\TransferEncoding;
use Ripple\Net\Http\Response;
use Ripple\Runtime\Exception\CoroutineStateException;
use Ripple\Runtime\Scheduler;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Time;

use function array_slice;
use function ctype_xdigit;
use function explode;
use function fopen;
use function hexdec;
use function is_callable;
use function is_resource;
use function is_string;
use function method_exists;
use function preg_match;
use function rewind;
use function strcasecmp;
use function str_contains;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

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
     * @var RequestBuilder
     */
    private RequestBuilder $requestBuilder;

    /**
     * @param array $config
     */
    public function __construct(private readonly array $config = [])
    {
        $this->connector = $config['connector'] ?? new Connector();
        $this->serializer = $config['serializer'] ?? new RequestSerializer();
        $this->options = ClientOptions::fromArray($config);
        $this->decoder = $config['decoder'] ?? new ResponseDecoder();
        $this->requestBuilder = $config['request_builder'] ?? new RequestBuilder();
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->sendRequestWithTransfer($request, new TransferOptions(
            false,
            null,
            null,
            $request->getBody()->getSize() ?? (int)$request->getHeaderLine('Content-Length')
        ));
    }

    /**
     * @param RequestInterface $request
     * @param TransferOptions $transfer
     * @return ResponseInterface
     * @throws ConnectionException
     */
    private function sendRequestWithTransfer(RequestInterface $request, TransferOptions $transfer): ResponseInterface
    {
        if (isset($this->config['sender'])) {
            return ($this->config['sender'])($request);
        }

        $stream = $this->connector instanceof Connector
            ? $this->connector->connect($request->getUri(), $this->options->connectTimeout())
            : ($this->connector)($request->getUri(), $this->config);

        $closeStream = true;
        try {
            $this->assertRequestNotExpired($request);
            $this->writeRequest($stream, $request, $transfer);
            $this->assertRequestNotExpired($request);

            if ($transfer->hasSink()) {
                return $this->receiveToSink($stream, $request, $transfer);
            }
            if ($transfer->streamResponse()) {
                $response = $this->receiveAsStream($stream, $request, $transfer);
                $closeStream = false;
                return $response;
            }

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
            if ($closeStream) {
                $stream->close();
            }
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
        [$request, $transfer] = $this->requestBuilder->build($method, $uri, $options);

        return $this->sendRequestWithTransfer($request, $transfer);
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
     * @param TransferOptions $transfer
     * @return void
     * @throws ConnectionException
     */
    private function writeRequest(object $stream, RequestInterface $request, TransferOptions $transfer): void
    {
        $stream->writeAll($this->serializer->serializeHeaders($request), $this->options->writeTimeout());

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $uploaded = 0;
        while (!$body->eof()) {
            $chunk = $body->read($this->options->uploadChunkSize());
            if ($chunk === '') {
                break;
            }

            $stream->writeAll($chunk, $this->options->writeTimeout());
            $uploaded += strlen($chunk);
            $this->notifyProgress($transfer, 0, 0, $transfer->uploadTotal(), $uploaded);
            $this->assertRequestNotExpired($request);
        }
    }

    /**
     * @param TransferOptions $transfer
     * @param int $downloadTotal
     * @param int $downloaded
     * @param int $uploadTotal
     * @param int $uploaded
     * @return void
     */
    private function notifyProgress(TransferOptions $transfer, int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): void
    {
        $progress = $transfer->progress();
        if (is_callable($progress)) {
            $progress($downloadTotal, $downloaded, $uploadTotal, $uploaded);
        }
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @param TransferOptions $transfer
     * @return Response
     * @throws ConnectionException
     */
    private function receiveToSink(object $stream, RequestInterface $request, TransferOptions $transfer): MessageInterface
    {
        [$statusCode, $headers, $reasonPhrase, $protocolVersion, $buffer] = $this->readFinalResponseHead($stream, $request);
        $sink = $this->openSink($transfer->sink());

        if ($this->isNoBodyResponse($request, $statusCode)) {
            return (new Response($statusCode, $headers, $this->sinkBody($sink), $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        $isChunked = $this->isChunked($headers);
        $contentLength = $this->contentLength($headers);
        if ($isChunked && $contentLength !== null) {
            throw new ProtocolException('Response cannot contain both Content-Length and Transfer-Encoding.');
        }

        if ($isChunked) {
            $downloaded = $this->receiveChunkedBody($stream, $request, $transfer, $sink, $buffer);
            $headers = $this->removeHeader($headers, 'Transfer-Encoding');
            $headers['Content-Length'] = [(string)$downloaded];
        } elseif ($contentLength !== null) {
            $this->receiveFixedBody($stream, $request, $transfer, $sink, $buffer, $contentLength);
        } else {
            throw new ProtocolException('HTTP response body length is unknown.');
        }

        $this->flushSink($sink);
        return (new Response($statusCode, $headers, $this->sinkBody($sink), $reasonPhrase))->withProtocolVersion($protocolVersion);
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @param TransferOptions $transfer
     * @return Response
     * @throws ConnectionException
     */
    private function receiveAsStream(object $stream, RequestInterface $request, TransferOptions $transfer): MessageInterface
    {
        [$statusCode, $headers, $reasonPhrase, $protocolVersion, $buffer] = $this->readFinalResponseHead($stream, $request);

        if ($this->isNoBodyResponse($request, $statusCode)) {
            $stream->close();
            return (new Response($statusCode, $headers, '', $reasonPhrase))->withProtocolVersion($protocolVersion);
        }

        $isChunked = $this->isChunked($headers);
        $contentLength = $this->contentLength($headers);
        if ($isChunked && $contentLength !== null) {
            $stream->close();
            throw new ProtocolException('Response cannot contain both Content-Length and Transfer-Encoding.');
        }
        if (!$isChunked && $contentLength === null) {
            $stream->close();
            throw new ProtocolException('HTTP response body length is unknown.');
        }

        if ($isChunked) {
            $headers = $this->removeHeader($headers, 'Transfer-Encoding');
        }

        $body = new NetworkResponseStream(
            $buffer,
            $contentLength,
            $isChunked,
            $this->options->maxBodyBytes(),
            fn () => $this->readChunk($stream, $request),
            static fn () => $stream->close(),
            function (int $downloaded) use ($transfer, $contentLength): void {
                $this->notifyProgress($transfer, $contentLength ?? 0, $downloaded, $transfer->uploadTotal(), $transfer->uploadTotal());
            }
        );

        return (new Response($statusCode, $headers, $body, $reasonPhrase))->withProtocolVersion($protocolVersion);
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @return array{0:int,1:array,2:string,3:string,4:string}
     * @throws ConnectionException
     */
    private function readFinalResponseHead(object $stream, RequestInterface $request): array
    {
        $buffer = '';

        while (true) {
            $headerEnd = strpos($buffer, "\r\n\r\n");
            while ($headerEnd === false) {
                if ($this->options->maxHeaderBytes() > 0 && strlen($buffer) > $this->options->maxHeaderBytes()) {
                    throw new ProtocolException('HTTP response headers exceed configured limit.');
                }

                $buffer .= $this->readChunk($stream, $request);
                $headerEnd = strpos($buffer, "\r\n\r\n");
            }

            if ($this->options->maxHeaderBytes() > 0 && $headerEnd + 4 > $this->options->maxHeaderBytes()) {
                throw new ProtocolException('HTTP response headers exceed configured limit.');
            }

            $head = substr($buffer, 0, $headerEnd);
            $rest = substr($buffer, $headerEnd + 4);
            $lines = explode("\r\n", $head);
            $statusLine = $lines[0] ?? '';

            if (!preg_match('#^HTTP/(\d+(?:\.\d+)?)\s+(\d{3})\s*(.*)$#', $statusLine, $matches)) {
                throw new ProtocolException('Invalid HTTP status line.');
            }

            $statusCode = (int)$matches[2];
            if ($statusCode >= 100 && $statusCode < 200) {
                $buffer = $rest;
                continue;
            }

            return [
                $statusCode,
                $this->parseHeaders(array_slice($lines, 1)),
                trim($matches[3]),
                $matches[1],
                $rest,
            ];
        }
    }

    /**
     * @param array $lines
     * @return array
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, ':')) {
                throw new ProtocolException('Invalid HTTP header line.');
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param mixed $sink
     * @return StreamInterface
     */
    private function openSink(mixed $sink): StreamInterface
    {
        if (is_resource($sink)) {
            return new BodyStream($sink);
        }

        if ($sink instanceof StreamInterface) {
            return $sink;
        }

        if (is_string($sink)) {
            $resource = fopen($sink, 'w+b');
            if (is_resource($resource)) {
                return new BodyStream($resource);
            }
        }

        throw new ProtocolException('Invalid response sink.');
    }

    /**
     * @param mixed $sink
     * @return StreamInterface
     */
    private function sinkBody(mixed $sink): StreamInterface
    {
        if ($sink instanceof StreamInterface) {
            if ($sink->isSeekable()) {
                $sink->rewind();
            }
            return $sink;
        }
        if (is_resource($sink)) {
            rewind($sink);
            return new BodyStream($sink);
        }
        if (is_string($sink)) {
            $resource = fopen($sink, 'rb');
            if (is_resource($resource)) {
                return new BodyStream($resource);
            }
        }

        return BodyStream::fromString('');
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @param TransferOptions $transfer
     * @param StreamInterface $sink
     * @param string $buffer
     * @param int $contentLength
     * @return void
     * @throws ConnectionException
     */
    private function receiveFixedBody(object $stream, RequestInterface $request, TransferOptions $transfer, StreamInterface $sink, string $buffer, int $contentLength): void
    {
        $downloaded = 0;
        if ($buffer !== '') {
            $chunk = substr($buffer, 0, $contentLength);
            $this->writeSink($sink, $chunk);
            $downloaded += strlen($chunk);
            $this->assertBodyLimit($downloaded);
            $this->notifyProgress($transfer, $contentLength, $downloaded, $transfer->uploadTotal(), $transfer->uploadTotal());
        }

        while ($downloaded < $contentLength) {
            $chunk = $this->readChunk($stream, $request);
            $remaining = $contentLength - $downloaded;
            if (strlen($chunk) > $remaining) {
                $chunk = substr($chunk, 0, $remaining);
            }
            if ($chunk === '') {
                continue;
            }

            $this->writeSink($sink, $chunk);
            $downloaded += strlen($chunk);
            $this->assertBodyLimit($downloaded);
            $this->notifyProgress($transfer, $contentLength, $downloaded, $transfer->uploadTotal(), $transfer->uploadTotal());
        }
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @param TransferOptions $transfer
     * @param StreamInterface $sink
     * @param string $buffer
     * @return int
     * @throws ConnectionException
     */
    private function receiveChunkedBody(object $stream, RequestInterface $request, TransferOptions $transfer, StreamInterface $sink, string $buffer): int
    {
        $downloaded = 0;
        $offset = 0;

        while (true) {
            while (($lineEnd = strpos($buffer, "\r\n", $offset)) === false) {
                $buffer .= $this->readChunk($stream, $request);
            }

            $line = trim(substr($buffer, $offset, $lineEnd - $offset));
            $parts = explode(';', $line, 2);
            $hex = trim($parts[0]);
            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new ProtocolException('Invalid HTTP chunk size.');
            }

            $length = (int)hexdec($hex);
            $offset = $lineEnd + 2;

            if ($length === 0) {
                while (strpos($buffer, "\r\n\r\n", $offset) === false && substr($buffer, $offset, 2) !== "\r\n") {
                    $buffer .= $this->readChunk($stream, $request);
                }
                return $downloaded;
            }

            while (strlen($buffer) < $offset + $length + 2) {
                $buffer .= $this->readChunk($stream, $request);
            }

            if (substr($buffer, $offset + $length, 2) !== "\r\n") {
                throw new ProtocolException('Invalid HTTP chunk terminator.');
            }

            $chunk = substr($buffer, $offset, $length);
            $this->writeSink($sink, $chunk);
            $downloaded += $length;
            $this->assertBodyLimit($downloaded);
            $this->notifyProgress($transfer, 0, $downloaded, $transfer->uploadTotal(), $transfer->uploadTotal());
            $offset += $length + 2;
        }
    }

    /**
     * @param StreamInterface $sink
     * @param string $chunk
     * @return void
     */
    private function writeSink(StreamInterface $sink, string $chunk): void
    {
        $remaining = $chunk;
        while ($remaining !== '') {
            $written = $sink->write($remaining);
            if ($written <= 0) {
                throw new ProtocolException('Unable to write response sink.');
            }
            $remaining = substr($remaining, $written);
        }
    }

    /**
     * @param StreamInterface $sink
     * @return void
     */
    private function flushSink(StreamInterface $sink): void
    {
        if (method_exists($sink, 'flush')) {
            $sink->flush();
        }
    }

    /**
     * @param int $bytes
     * @return void
     */
    private function assertBodyLimit(int $bytes): void
    {
        if ($this->options->maxBodyBytes() > 0 && $bytes > $this->options->maxBodyBytes()) {
            throw new ProtocolException('HTTP response body exceeds configured limit.');
        }
    }

    /**
     * @param array $headers
     * @return int|null
     */
    private function contentLength(array $headers): ?int
    {
        $length = null;
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Content-Length') !== 0) {
                continue;
            }
            foreach ($values as $value) {
                $current = (int)trim((string)$value);
                if ($length !== null && $length !== $current) {
                    throw new ProtocolException('Conflicting Content-Length headers.');
                }
                $length = $current;
            }
        }

        return $length;
    }

    /**
     * @param array $headers
     * @return bool
     */
    private function isChunked(array $headers): bool
    {
        return TransferEncoding::isChunked($headers);
    }

    /**
     * @param RequestInterface $request
     * @param int $statusCode
     * @return bool
     */
    private function isNoBodyResponse(RequestInterface $request, int $statusCode): bool
    {
        return strtoupper($request->getMethod()) === 'HEAD' || $statusCode === 204 || $statusCode === 304;
    }

    /**
     * @param array $headers
     * @param string $name
     * @return array
     */
    private function removeHeader(array $headers, string $name): array
    {
        foreach ($headers as $headerName => $_) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                unset($headers[$headerName]);
            }
        }

        return $headers;
    }

    /**
     * @param object $stream
     * @param RequestInterface $request
     * @return string
     * @throws ConnectionException
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
            $stream->unwatchRead();
        }
    }
}
