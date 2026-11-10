<?php

namespace App\Domain\Pos\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A cash register cart (one tab of one seller in one store). data:
 *   lines:    [{kind: unit, unit_id, discount} | {kind: accessory, accessory_id, qty, discount, gift}]  (discount — tiyn)
 *   customer_id, check_discount, discount_reason, payments: [{method, amount, tendered?, due_date?}],
 *   source, comment, delivery, order_document_id, print: {receipt, warranty, kk}
 */
class Cart extends Model
{
    use BelongsToOrganization;

    protected $table = 'pos_carts';

    protected $guarded = ['id', 'organization_id'];

    public const EMPTY = ['lines' => [], 'customer_id' => null, 'check_discount' => 0, 'discount_reason' => null, 'payments' => [],
        'source' => null, 'comment' => null, 'delivery' => null, 'order_document_id' => null];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer', 'tab' => 'integer'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function isEmpty(): bool
    {
        return ($this->data['lines'] ?? []) === [];
    }
}
