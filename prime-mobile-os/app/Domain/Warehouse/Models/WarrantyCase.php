<?php

namespace App\Domain\Warehouse\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class WarrantyCase extends Model
{
    use BelongsToOrganization;

    protected $table = 'warranty_cases';

    protected $guarded = ['id', 'organization_id'];

    public const STATUSES = ['accepted' => 'Принят', 'in_repair' => 'В ремонте у нас', 'at_service' => 'В сервисе', 'ready' => 'Готов к выдаче', 'issued' => 'Выдан', 'refused' => 'Отказ'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
