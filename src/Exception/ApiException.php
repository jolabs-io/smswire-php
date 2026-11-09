<?php

declare(strict_types=1);

namespace SMSWire\Exception;

use RuntimeException;
use Throwable;

/** A request that failed: the API's answer, or a network problem (status null). */
class ApiException extends RuntimeException
{
    public function __construct(
        /** HTTP status, or null when no answer arrived. */
        public readonly ?int $status,
        /** Stable machine code: invalid_request, rate_limited, network_error, timeout, … */
        public readonly string $errorCode,
        string $message,
        /** Seconds until trying again makes sense (from Retry-After), when the API said. */
        public readonly ?float $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
