<?php

namespace App\Domain\Debts\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Detail of «Корректировка долга» КДЛ (TZ 02 §6.2 №35, TZ 03 права 26 и «Перенос срока долга»). */
class DebtAdjustment extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_date' => 'date', 'previous_due_date' => 'date'];
    }
}
