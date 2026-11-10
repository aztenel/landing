<?php

namespace Tests\Support;

/** Test alias of the production nightly checks (App\Domain\Ledger\Services\Reconciliation). */
final class Reconciliation
{
    /** @return list<string> */
    public static function problems(): array
    {
        return \App\Domain\Ledger\Services\Reconciliation::problems();
    }
}
