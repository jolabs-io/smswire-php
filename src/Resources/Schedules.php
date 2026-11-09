<?php

declare(strict_types=1);

namespace SMSWire\Resources;

use SMSWire\Http\Request;
use SMSWire\Http\Transport;
use SMSWire\Support;

final class Schedules
{
    public function __construct(private readonly Transport $transport, private readonly string $base)
    {
    }

    /**
     * A message (or batch) sent again and again on a cron schedule:
     * ['cron' => '0 9 * * 1', 'timezone' => 'Africa/Addis_Ababa', 'to' => …, 'body' => …].
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->transport->send(new Request('POST', $this->base, body: Support::body($params), idempotent: false));
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function list(): array
    {
        return $this->transport->send(new Request('GET', $this->base));
    }

    /**
     * One schedule, with its recent runs.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->transport->send(new Request('GET', $this->base . '/' . rawurlencode($id)));
    }

    /**
     * Only the keys given change (null clears); recipients can't change.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $id, array $changes): array
    {
        return $this->transport->send(new Request('PATCH', $this->base . '/' . rawurlencode($id), body: Support::body($changes, keepNull: true)));
    }

    public function delete(string $id): void
    {
        $this->transport->send(new Request('DELETE', $this->base . '/' . rawurlencode($id)));
    }
}
