<?php

declare(strict_types=1);

namespace SMSWire\Exception;

use RuntimeException;

/** A webhook whose signature is missing, wrong or too old. Answer it with 400. */
class SignatureException extends RuntimeException
{
}
