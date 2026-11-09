<?php

declare(strict_types=1);

namespace SMSWire;

use DateTimeInterface;

/** @internal */
final class Support
{
    /** A random UUID v4, the default Idempotency-Key. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * A JSON body: dates become ISO 8601, nulls are left out unless they mean "clear".
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function body(array $params, bool $keepNull = false): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null && !$keepNull) {
                continue;
            }
            $out[$key] = $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function query(array $filters): array
    {
        return self::body($filters);
    }
}
