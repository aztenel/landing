<?php

namespace App\Domain\Ledger\Models;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money journal row (append-only). Written only by MoneyLedger. */
class MoneyEntry extends Model
{
    use AppendOnly, BelongsToOrganization;

    public $timestamps = false;

    /** Journal row: written only by the ledgers (forceFill), never mass-assigned (ENGINEERING.md rule 4). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount' => 'integer',
            'amount_kzt' => 'integer',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
