<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Copy of customer bonuses (available / pending). Changed only by BonusLedger. */
class CustomerBonusBalance extends Model
{
    use BelongsToOrganization;

    public const CREATED_AT = null;

    /** Maintained only by the ledgers inside posting (forceFill), never mass-assigned (ENGINEERING.md rule 5). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['available' => 'integer', 'pending' => 'integer', 'next_expire_at' => 'datetime'];
    }
}
