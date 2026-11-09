<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\BonusKind;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Bonus journal row (append-only), whole bonuses. Written only by BonusLedger. */
class BonusEntry extends Model
{
    use AppendOnly, BelongsToOrganization;

    public $timestamps = false;

    /** Journal row: written only by the ledgers (forceFill), never mass-assigned (ENGINEERING.md rule 4). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => BonusKind::class,
            'amount' => 'integer',
            'is_source' => 'boolean',
            'available_from' => 'datetime',
            'expires_at' => 'datetime',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
