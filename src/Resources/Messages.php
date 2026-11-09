<?php

declare(strict_types=1);

namespace SMSWire\Resources;

use Generator;
use SMSWire\Http\Request;
use SMSWire\Http\Transport;
use SMSWire\Support;

final class Messages
{
    public function __construct(private readonly Transport $transport, private readonly string $base)
    {
    }

    /**
     * Queues one SMS: ['to' => '+251…', 'body' => '…'] plus optional template, variables, sim, type,
     * tags, metadata and send_at. A random Idempotency-Key makes the SDK's own retries safe; pass
     * your own (an order id, say) to make your retries safe too.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> the message
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        return $this->transport->send(new Request(
            'POST',
            $this->base,
            body: Support::body($params),
            headers: ['Idempotency-Key' => $idempotencyKey ?? Support::uuid()],
            idempotent: true,
        ));
    }

    /**
     * Queues a batch: ['messages' => [['to' => …, 'variables' => […]], …], 'body' => …].
     * Invalid or opted-out items come back in 'rejected'.
     *
     * @param array<string, mixed> $params
     * @return array{batch_id: string, accepted: int, rejected: list<array{index: int, to: string, reason: string}>}
     */
    public function sendBulk(array $params): array
    {
        return $this->transport->send(new Request('POST', $this->base . '/bulk', body: Support::body($params), idempotent: false));
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->transport->send(new Request('GET', $this->base . '/' . rawurlencode($id)));
    }

    /**
     * Stops a message that hasn't reached a phone yet.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->transport->send(new Request('DELETE', $this->base . '/' . rawurlencode($id)));
    }

    /**
     * One page, newest first. Filters: status, type, tag, to, batch_id, from, until, cursor, limit.
     *
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(array $filters = []): array
    {
        return $this->transport->send(new Request('GET', $this->base, query: Support::query($filters)));
    }

    /**
     * Every matching message, newest first, fetching pages as you go.
     *
     * @param array<string, mixed> $filters
     * @return Generator<int, array<string, mixed>>
     */
    public function listAll(array $filters = []): Generator
    {
        $cursor = null;
        do {
            $page = $this->list(['cursor' => $cursor] + $filters);
            foreach ($page['data'] as $message) {
                yield $message;
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);
    }
}
