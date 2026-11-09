<?php

namespace App\Support\Idempotency;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Protection against double «Провести» (TZ 02 §2.4). A form receives a key when it opens; the draft document
 * stores it in documents.idempotency_key (unique per organization). Posting a document whose key is already
 * posted returns that document instead of writing anything twice — see Poster::post() and Document::findByIdempotencyKey().
 */
final class Idempotency
{
    public static function newKey(): string
    {
        return (string) Str::ulid();
    }

    public static function isValid(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9:_\-\.]{8,100}$/', $key);
    }

    public static function assertValid(string $key): string
    {
        if (! self::isValid($key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }

        return $key;
    }

    /** Deterministic key for system-generated documents (e.g. reversal of document 42 → "reversal:42"). */
    public static function derived(string $scope, int|string ...$parts): string
    {
        return self::assertValid($scope.':'.implode(':', $parts));
    }
}
