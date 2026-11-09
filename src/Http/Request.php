<?php

declare(strict_types=1);

namespace SMSWire\Http;

/** @internal */
final class Request
{
    /**
     * @param array<string, mixed>|null $query
     * @param array<string, string> $headers
     * @param bool $idempotent Safe to repeat after a 5xx or a dropped connection (GET, PATCH, DELETE, or a POST with an Idempotency-Key).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $query = null,
        public readonly mixed $body = null,
        public readonly array $headers = [],
        public readonly bool $idempotent = true,
    ) {
    }
}
