<?php

namespace App\Domain\Orders\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * «Заказ (бронь)» ЗАК (TZ 01 П10.2, 02 №22). Status after posting: active → sold | cancelled | expired, or overdue
 * (the hold ran out with a prepayment: the phone stays held until the seller extends or cancels).
 */
class Order extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'price' => 'integer', 'list_price' => 'integer', 'prepayment' => 'integer', 'extended_count' => 'integer',
            'hold_until' => 'datetime', 'overdue_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['active', 'overdue'], true);
    }
}
