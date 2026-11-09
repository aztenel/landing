<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Business rule violation whose message is written for the person at the screen (Russian, no internals):
 * «Телефон уже продан (ПРД-2026-000012, 14:32, Айгерим)». Screens show getMessage() as is.
 */
class UserFacingException extends RuntimeException
{
    /** Machine-readable reason for UI branching and tests. */
    public string $reason = 'rule';

    public array $context = [];

    public static function because(string $reason, string $message, array $context = []): static
    {
        $e = new static($message);
        $e->reason = $reason;
        $e->context = $context;

        return $e;
    }
}
