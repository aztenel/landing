<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Cash register shift (TZ 02 §8). One open shift per cash account (partial unique index). Logic: Money module. */
class Shift extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'closing_denominations' => 'array',
        ];
    }
}
