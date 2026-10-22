<?php

namespace App\Services;

use RuntimeException;

/**
 * A completion-confirmation rule was not met; carries the HTTP status to answer with.
 */
class ConfirmationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
