<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Which source entries covered a consuming entry (FIFO); negative rows restore bonuses with their dates. */
class BonusAllocation extends Model
{
    use AppendOnly, BelongsToOrganization;

    public const UPDATED_AT = null;

    /** Maintained only by the ledgers inside posting (forceFill), never mass-assigned (ENGINEERING.md rule 5). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
