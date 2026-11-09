<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Copy of an account balance (Σ money_entries.amount). Changed only by MoneyLedger. */
class AccountBalance extends Model
{
    use BelongsToOrganization;

    public const CREATED_AT = null;

    /** Maintained only by the ledgers inside posting (forceFill), never mass-assigned (ENGINEERING.md rule 5). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'balance_kzt_hist' => 'integer'];
    }
}
