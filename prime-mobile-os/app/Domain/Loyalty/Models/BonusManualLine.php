<?php

namespace App\Domain\Loyalty\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Line of «Бонусы вручную» БНС (kind manual) or «Сгорание бонусов» БСГ (kind expire). Whole bonuses, 1 bonus = 1 ₸. */
class BonusManualLine extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
