<?php

namespace App\Domain\Money\Models;

use App\Domain\Money\Enums\PaymentMethodCode;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment method setup of the organization: default account and commission % (TZ 02 §11.4).
 * A store-specific row overrides the organization-wide row (store_id NULL).
 */
class PaymentMethod extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'store_id', 'code', 'name_ru', 'name_kk', 'account_id', 'commission_percent', 'is_refundable', 'is_enabled', 'sort'];

    protected function casts(): array
    {
        return [
            'code' => PaymentMethodCode::class,
            'is_refundable' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'account_id');
    }

    public static function resolve(PaymentMethodCode|string $code, ?int $storeId): ?self
    {
        $code = $code instanceof PaymentMethodCode ? $code->value : $code;

        return static::query()
            ->where('code', $code)
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('store_id')->when($storeId, fn ($q) => $q->orWhere('store_id', $storeId)))
            ->orderByRaw('store_id IS NULL') // store-specific first
            ->first();
    }
}
