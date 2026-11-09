<?php

declare(strict_types=1);

namespace SMSWire\Resources;

use SMSWire\Http\Request;
use SMSWire\Http\Transport;
use SMSWire\Support;

final class Otp
{
    public function __construct(private readonly Transport $transport, private readonly string $base)
    {
    }

    /**
     * Texts a fresh code: ['to' => '+251…'] plus optional length, ttl, body (with {{code}}),
     * template and sim. Keep the returned 'id' for verify().
     *
     * @param array<string, mixed> $params
     * @return array{id: string, to: string, expires_at: string, message_id: string}
     */
    public function send(array $params): array
    {
        return $this->transport->send(new Request('POST', $this->base, body: Support::body($params), idempotent: false));
    }

    /**
     * Checks what the user typed. 'valid' is false with a 'reason' (invalid, expired,
     * too_many_attempts, already_used) when it's wrong.
     *
     * @return array{valid: bool, reason?: ?string}
     */
    public function verify(string $id, string $code): array
    {
        return $this->transport->send(new Request('POST', $this->base . '/verify', body: ['id' => $id, 'code' => $code], idempotent: false));
    }
}
