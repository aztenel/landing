<?php

namespace App\Domain\Ledger\Posting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Posting transaction with the retry policy of TZ 02 §12.3: on a deadlock, a lock timeout (lock_timeout = 5 s)
 * or a serialization failure the whole transaction is repeated up to 3 times with a 100–300 ms pause. Safe because
 * posting is idempotent (the document row is re-read under FOR UPDATE, ТЗ 02 §2.4).
 *
 * Laravel's own DB::transaction($fn, 3) retries deadlocks only, without a pause, and never a PostgreSQL lock
 * timeout (SQLSTATE 55P03). Inside an outer transaction nothing is retried (the outer one is already aborted).
 */
final class TransactionRetry
{
    /** deadlock_detected, serialization_failure, lock_not_available (lock_timeout) */
    public const RETRYABLE = ['40P01', '40001', '55P03'];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback, int $attempts = 3, int $minPauseMs = 100, int $maxPauseMs = 300): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $e) {
                if ($attempt >= $attempts || DB::transactionLevel() > 0 || ! self::isRetryable($e)) {
                    throw $e;
                }
                usleep(random_int($minPauseMs, $maxPauseMs) * 1000);
            }
        }
    }

    public static function isRetryable(Throwable $e): bool
    {
        $state = $e instanceof QueryException && isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();

        return in_array($state, self::RETRYABLE, true);
    }
}
