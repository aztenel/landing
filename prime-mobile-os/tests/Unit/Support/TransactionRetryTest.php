<?php

use App\Domain\Ledger\Posting\TransactionRetry;
use Illuminate\Database\QueryException;

function pgError(string $sqlState): QueryException
{
    $pdo = new class("SQLSTATE[{$sqlState}]: simulated") extends PDOException {};
    $pdo->errorInfo = [$sqlState, 7, 'simulated'];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

it('repeats a posting transaction on a lock timeout or deadlock up to 3 times (TZ 02 §12.3)', function () {
    $calls = 0;
    $result = TransactionRetry::run(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw pgError($calls === 1 ? '55P03' : '40P01');
        }

        return 'posted';
    }, minPauseMs: 1, maxPauseMs: 2);

    expect($result)->toBe('posted')->and($calls)->toBe(3);
});

it('gives up after 3 attempts and never retries other errors', function () {
    $calls = 0;
    expect(function () use (&$calls) {
        TransactionRetry::run(function () use (&$calls) {
            $calls++;
            throw pgError('55P03');
        }, minPauseMs: 1, maxPauseMs: 2);
    })->toThrow(QueryException::class);
    expect($calls)->toBe(3);

    $calls = 0;
    expect(function () use (&$calls) {
        TransactionRetry::run(function () use (&$calls) {
            $calls++;
            throw pgError('23505');
        });
    })->toThrow(QueryException::class);
    expect($calls)->toBe(1);
});
