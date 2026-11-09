<?php

declare(strict_types=1);

namespace SMSWire;

use SMSWire\Exception\SignatureException;

/** Verifies the webhooks SMSWire POSTs (message.status, message.received, alert.*). */
final class Webhook
{
    /** The header the signature arrives in. */
    public const SIGNATURE_HEADER = 'X-SMSWire-Signature';

    /**
     * Checks X-SMSWire-Signature against the **raw** request body (file_get_contents('php://input'),
     * not re-encoded JSON) and returns the event as an array.
     *
     * @return array<string, mixed>
     * @throws SignatureException when the signature is missing, wrong, or more than $tolerance seconds off
     */
    public static function verify(string $rawBody, ?string $signature, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        if ($secret === '') {
            throw new SignatureException('No webhook signing secret given');
        }
        $t = null;
        $signatures = [];
        foreach (explode(',', $signature ?? '') as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = [trim($pair[0]), trim($pair[1])];
            if ($key === 't' && ctype_digit($value)) {
                $t = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }
        if ($t === null || $signatures === []) {
            throw new SignatureException('Missing or malformed ' . self::SIGNATURE_HEADER . ' header');
        }
        if (abs(($now ?? time()) - $t) > $tolerance) {
            throw new SignatureException('Signature timestamp is outside the tolerance');
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($signatures as $given) {
            if (hash_equals($expected, $given)) {
                return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw new SignatureException('Signature does not match');
    }
}
