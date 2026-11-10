<?php

namespace App\Domain\TradeIn\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TradeInPayout extends Model
{
    use BelongsToOrganization;

    protected $table = 'tradein_payouts';

    protected $guarded = ['id', 'organization_id'];
}
