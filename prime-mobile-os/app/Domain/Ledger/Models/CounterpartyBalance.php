<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Copy of a counterparty debt per currency (Σ debt_entries). + they owe us, − we owe. Changed only by DebtLedger. */
class CounterpartyBalance extends Model
{
    use BelongsToOrganization;

    public const CREATED_AT = null;

    /** Maintained only by the ledgers inside posting (forceFill), never mass-assigned (ENGINEERING.md rule 5). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'balance_kzt' => 'integer', 'nearest_due_date' => 'date'];
    }
}
