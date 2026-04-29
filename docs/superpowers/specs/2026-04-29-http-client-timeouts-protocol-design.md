# HTTP Client Timeouts and Protocol Hardening Design

## Context

`Ripple\Net\Http\Client\Client` currently provides a minimal PSR-style HTTP/1.1 client. It opens one connection per request, serializes the request into a single string, reads until `ResponseParser` returns one complete response, and then closes the stream.

This design covers the first production-hardening pass:

- add the missing PSR HTTP client dependency;
- replace coarse read behavior with explicit client timeout controls;
- harden response parsing for common HTTP/1.1 edge cases;
- validate outgoing request headers and body framing;
- add gzip/deflate automatic response decoding, with brotli only when runtime support exists.

Streaming upload and streaming response delivery are intentionally out of scope for this pass. The client may continue returning fully aggregated response bodies until the later body streaming work.

## Goals

1. Make the package dependency graph match the public client API by requiring `psr/http-client`.
2. Give HTTP client operations predictable timeout boundaries:
   - connect timeout;
   - write timeout;
   - read timeout;
   - total request timeout.
3. Parse HTTP/1.1 responses correctly enough for real services:
   - informational `1xx` responses;
   - no-body responses for `204`, `304`, and `HEAD`;
   - chunk extensions and trailers;
   - close-delimited response bodies;
   - repeated or conflicting `Content-Length`;
   - header and body byte limits.
4. Prevent malformed outbound requests:
   - header name/value validation;
   - CRLF injection rejection;
   - `Content-Length` and `Transfer-Encoding` conflict detection;
   - default `User-Agent` and `Accept-Encoding`.
5. Decode gzip/deflate response bodies by default, and support brotli only when available.

## Non-Goals

- Persistent connection pooling and keep-alive reuse.
- Streaming response bodies and sink/progress support.
- Streaming or chunked request uploads.
- Redirects, retries, cookies, proxy support, and HTTP/2.
- Full PSR-18 exception hierarchy implementation beyond what is required to support this pass cleanly.

## Runtime Model

The client should follow the existing framework control style:

- use `Stream::connect()` for asynchronous connection setup;
- use `Stream::writeAll($bytes, $timeout)` for request writes;
- use `Stream::watchRead()` and `Time::afterFunc()` for read waits;
- resume or throw into the owning coroutine through `Scheduler::resume()` and `Scheduler::throw()`;
- always cancel read watchers and timers in `finally` blocks.

The read helper should be local to the HTTP client layer. It should wait for read readiness, read a chunk, feed it into the parser, and enforce both per-read timeout and total request deadline. It must not busy-loop on empty reads.

## Client Options

Introduce an internal options object or normalized config array with these defaults:

| Option | Default | Meaning |
| --- | ---: | --- |
| `connect_timeout` | `10.0` | Maximum seconds for TCP connect and TLS setup. |
| `write_timeout` | `10.0` | Maximum seconds for sending serialized request bytes. |
| `read_timeout` | `30.0` | Maximum idle seconds waiting for response data. |
| `request_timeout` | `0.0` | Total request deadline; `0.0` means disabled. |
| `max_header_bytes` | `65536` | Maximum bytes before response headers complete. |
| `max_body_bytes` | `0` | Maximum aggregated response body bytes; `0` means unlimited. |
| `decode_content` | `true` | Whether to auto-decode supported content encodings. |

When `request_timeout` is enabled, each phase should use the smaller of its phase timeout and the remaining request deadline.

## Response Parser Design

`ResponseParser` should become a stricter stateful parser while still returning complete `Response` objects in this pass.

Header handling:

- enforce `max_header_bytes` while searching for `\r\n\r\n`;
- reject invalid status lines;
- accept and skip informational `100`-`199` responses until a final response is parsed;
- collect headers case-insensitively without losing original values;
- reject conflicting `Content-Length` values;
- allow repeated `Content-Length` only when all values are identical.

Body framing:

- for request method `HEAD`, and status `204` or `304`, force an empty body;
- for `Transfer-Encoding: chunked`, parse chunks until the zero chunk;
- ignore chunk extensions after `;` in the chunk-size line;
- read and consume trailer header lines after the zero chunk;
- remove or normalize `Transfer-Encoding` after decoding chunked framing;
- for `Content-Length`, require exactly that many bytes before completing;
- when neither length nor transfer encoding is present, support close-delimited bodies by reading until EOF, bounded by read timeout and `max_body_bytes`.

Limits:

- enforce `max_body_bytes` for all body modes;
- throw a protocol exception when limits are exceeded;
- do not return partial final responses on timeout or malformed input.

## Request Serializer Design

`RequestSerializer` should remain responsible for HTTP/1.1 request-line and header serialization, but it must reject unsafe data:

- validate header names against HTTP token syntax;
- reject header values containing `\r` or `\n`;
- reject simultaneous `Content-Length` and `Transfer-Encoding`;
- reject unsupported `Transfer-Encoding` on requests in this pass;
- preserve caller-provided `Host`, `User-Agent`, and `Accept-Encoding`;
- add `Host` from the URI when absent;
- add a default `User-Agent`;
- add `Accept-Encoding: gzip, deflate`, plus `br` only when brotli decode support is detected;
- add `Content-Length` for non-empty known string bodies when absent.

The serializer may still stringify request bodies for this pass. That limitation should stay explicit in tests and comments where relevant.

## Automatic Decoding

When `decode_content` is true:

- decode `Content-Encoding: gzip` and `Content-Encoding: deflate` using available zlib functions;
- decode `br` only when a brotli decoder exists in the runtime;
- if brotli is unavailable, do not advertise `br` by default;
- after successful decoding, remove `Content-Encoding`;
- remove `Content-Length` or replace it with the decoded body length;
- preserve status code, reason phrase, protocol version, and unrelated headers.

Decode failures should produce a protocol-level exception rather than returning corrupt body bytes.

## Error Handling

This pass should introduce clear internal exception types for:

- timeout;
- malformed response/protocol violation;
- unsafe or invalid request serialization;
- unsupported content encoding.

The exact PSR-18 exception interface mapping can be completed in the next pass, but new exceptions should be easy to wrap under `Psr\Http\Client\ClientExceptionInterface`.

## Test Plan

Add focused unit tests for:

- `composer.json` requiring `psr/http-client`;
- read timeout and total request timeout;
- write timeout continuing to use `Stream::writeAll`;
- `1xx` followed by final response;
- `204`, `304`, and `HEAD` responses with no body;
- chunk extensions and trailers;
- close-delimited response bodies;
- repeated identical `Content-Length`;
- conflicting `Content-Length`;
- header byte limit;
- body byte limit;
- header CRLF injection rejection;
- `Content-Length` plus `Transfer-Encoding` rejection;
- default `User-Agent` and `Accept-Encoding`;
- gzip and deflate decoding;
- brotli advertisement only when decode support exists.

Integration tests should use local socket pairs or the existing HTTP server helpers so they remain deterministic and do not depend on external network services.
