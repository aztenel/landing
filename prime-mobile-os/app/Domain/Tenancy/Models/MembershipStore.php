<?php

namespace App\Domain\Tenancy\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A store an employee works in, with the marks «Продажи» and «Финансы» (TZ 03 §1, audit №10). */
class MembershipStore extends Model
{
    use BelongsToOrganization;

    protected $table = 'membership_stores';

    protected $fillable = ['membership_id', 'store_id', 'can_sales', 'can_finance'];

    protected function casts(): array
    {
        return ['can_sales' => 'boolean', 'can_finance' => 'boolean'];
    }
}
