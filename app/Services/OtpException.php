<?php

namespace App\Services;

use RuntimeException;

/**
 * OTP error with a machine code + HTTP status + optional extra data.
 */
class OtpException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }
}
