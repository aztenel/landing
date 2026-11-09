<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Month of the organization: open / closed (TZ 02 §9). organizations.closed_until is the fast copy. */
class AccountingPeriod extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }
}
