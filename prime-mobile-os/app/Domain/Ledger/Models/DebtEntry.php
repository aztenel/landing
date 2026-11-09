<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Ledger\Enums\DebtKind;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Debt journal row (append-only). Written only by DebtLedger. */
class DebtEntry extends Model
{
    use AppendOnly, BelongsToOrganization;

    public $timestamps = false;

    /** Journal row: written only by the ledgers (forceFill), never mass-assigned (ENGINEERING.md rule 4). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'counterparty_type' => CounterpartyType::class,
            'currency' => Currency::class,
            'kind' => DebtKind::class,
            'amount' => 'integer',
            'amount_kzt' => 'integer',
            'fx_diff_kzt' => 'integer',
            'due_date' => 'date',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
