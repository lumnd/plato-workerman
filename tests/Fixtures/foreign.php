<?php

namespace Tests\Fixtures;

use plato\server\driver;

/**
 * A server driver that is not this package's.
 *
 * The `server:` commands drive Workerman and say so rather than doing something surprising to a
 * listener served by somebody else's adapter; this is the somebody else.
 */
class foreign implements driver
{
    /** @var array<string, mixed> */
    private $_config = [];

    public function configure(array $config): void
    {
        $this->_config = $config;
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function send(string $id, string $payload): bool
    {
        return false;
    }

    public function close(string $id, int $code = 1000, string $reason = ''): bool
    {
        return false;
    }

    public function connection(string $id)
    {
        return null;
    }

    /** @return array<int, string> */
    public function connections(): array
    {
        return [];
    }
}
