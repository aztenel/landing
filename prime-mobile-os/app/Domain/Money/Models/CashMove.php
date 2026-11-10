<?php

namespace App\Domain\Money\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Detail of «Движение денег» ДДС (TZ 02 §6.2 №35–38, audit №28). Rows of a posted document are read-only except the collection status. */
class CashMove extends Model
{
    use BelongsToOrganization;

    protected $table = 'cash_moves';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'fee_amount' => 'integer'];
    }
}
