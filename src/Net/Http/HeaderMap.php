<?php declare(strict_types=1);

namespace Ripple\Net\Http;

use InvalidArgumentException;

use function array_map;
use function implode;
use function is_array;
use function preg_match;
use function strtolower;
use function array_push;

final class HeaderMap
{
    /**
     * @var array
     */
    private array $headers = [];

    /**
     * @param array $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->set((string)$name, $value);
        }
    }

    /**
     * @return array
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->headers as $header) {
            $result[$header['name']] = $header['values'];
        }

        return $result;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @param string $name
     * @return array
     */
    public function get(string $name): array
    {
        return $this->headers[strtolower($name)]['values'] ?? [];
    }

    /**
     * @param string $name
     * @return string
     */
    public function line(string $name): string
    {
        return implode(', ', $this->get($name));
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function set(string $name, mixed $value): void
    {
        $this->assertName($name);
        $this->headers[strtolower($name)] = [
            'name' => $name,
            'values' => $this->normalizeValues($value),
        ];
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function add(string $name, mixed $value): void
    {
        $this->assertName($name);
        $key = strtolower($name);
        $values = $this->normalizeValues($value);

        if (!isset($this->headers[$key])) {
            $this->headers[$key] = ['name' => $name, 'values' => $values];
            return;
        }

        array_push($this->headers[$key]['values'], ...$values);
    }

    /**
     * @param string $name
     * @return void
     */
    public function remove(string $name): void
    {
        unset($this->headers[strtolower($name)]);
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function normalizeValues(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_map(static fn (mixed $item): string => (string)$item, $values);
    }

    /**
     * @param string $name
     * @return void
     */
    private function assertName(string $name): void
    {
        if ($name === '' || !preg_match('/^[A-Za-z0-9\'`#$%&*+.^_|~!-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid HTTP header name.');
        }
    }
}
