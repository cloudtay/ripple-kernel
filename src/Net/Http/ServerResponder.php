<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use Psr\Http\Message\ResponseInterface;

final class ServerResponder
{
    /**
     * @var ResponseInterface|null
     */
    private ?ResponseInterface $response = null;

    /**
     * @param ResponseInterface $response
     * @return void
     */
    public function respond(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * @return ResponseInterface|null
     */
    public function response(): ?ResponseInterface
    {
        return $this->response;
    }
}
