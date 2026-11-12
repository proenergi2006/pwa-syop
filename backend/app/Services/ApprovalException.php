<?php

namespace App\Services;

use RuntimeException;

/** Kesalahan bisnis approval yang aman ditampilkan ke pengguna. */
class ApprovalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $reason = 'APPROVAL_ERROR',
    ) {
        parent::__construct($message);
    }
}
