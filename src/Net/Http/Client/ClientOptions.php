<?php declare(strict_types=1);

namespace Ripple\Net\Http\Client;

use function max;
use function microtime;
use function min;

final class ClientOptions
{
    /**
     * @var float|null
     */
    private ?float $deadline = null;

    /**
     * @param float $connectTimeout
     * @param float $writeTimeout
     * @param float $readTimeout
     * @param float $requestTimeout
     * @param int $maxHeaderBytes
     * @param int $maxBodyBytes
     * @param bool $decodeContent
     */
    private function __construct(
        private readonly float $connectTimeout,
        private readonly float $writeTimeout,
        private readonly float $readTimeout,
        private readonly float $requestTimeout,
        private readonly int $maxHeaderBytes,
        private readonly int $maxBodyBytes,
        private readonly bool $decodeContent
    ) {
        if ($requestTimeout > 0.0) {
            $this->deadline = microtime(true) + $requestTimeout;
        }
    }

    /**
     * @param array $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self(
            self::positiveFloat($config['connect_timeout'] ?? 10.0, 10.0),
            self::positiveFloat($config['write_timeout'] ?? 10.0, 10.0),
            self::positiveFloat($config['read_timeout'] ?? 30.0, 30.0),
            self::nonNegativeFloat($config['request_timeout'] ?? 0.0),
            self::nonNegativeInt($config['max_header_bytes'] ?? 65536),
            self::nonNegativeInt($config['max_body_bytes'] ?? 0),
            (bool)($config['decode_content'] ?? true)
        );
    }

    /**
     * @return float
     */
    public function connectTimeout(): float
    {
        return $this->phaseTimeout($this->connectTimeout);
    }

    /**
     * @return float
     */
    public function writeTimeout(): float
    {
        return $this->phaseTimeout($this->writeTimeout);
    }

    /**
     * @return float
     */
    public function readTimeout(): float
    {
        return $this->phaseTimeout($this->readTimeout);
    }

    /**
     * @return float
     */
    public function requestTimeout(): float
    {
        return $this->requestTimeout;
    }

    /**
     * @return int
     */
    public function maxHeaderBytes(): int
    {
        return $this->maxHeaderBytes;
    }

    /**
     * @return int
     */
    public function maxBodyBytes(): int
    {
        return $this->maxBodyBytes;
    }

    /**
     * @return bool
     */
    public function decodeContent(): bool
    {
        return $this->decodeContent;
    }

    /**
     * @return float|null
     */
    public function remainingRequestTime(): ?float
    {
        if ($this->deadline === null) {
            return null;
        }

        return $this->deadline - microtime(true);
    }

    /**
     * @return bool
     */
    public function hasExpired(): bool
    {
        $remaining = $this->remainingRequestTime();
        return $remaining !== null && $remaining <= 0.0;
    }

    /**
     * @param float $timeout
     * @return float
     */
    private function phaseTimeout(float $timeout): float
    {
        $remaining = $this->remainingRequestTime();
        if ($remaining === null) {
            return $timeout;
        }

        return max(0.001, min($timeout, $remaining));
    }

    /**
     * @param mixed $value
     * @param float $default
     * @return float
     */
    private static function positiveFloat(mixed $value, float $default): float
    {
        $value = (float)$value;
        return $value > 0.0 ? $value : $default;
    }

    /**
     * @param mixed $value
     * @return float
     */
    private static function nonNegativeFloat(mixed $value): float
    {
        return max(0.0, (float)$value);
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function nonNegativeInt(mixed $value): int
    {
        return max(0, (int)$value);
    }
}
