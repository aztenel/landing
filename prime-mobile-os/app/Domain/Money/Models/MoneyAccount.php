<?php

namespace App\Domain\Money\Models;

use App\Domain\Money\Enums\AccountType;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;

/**
 * A place where money lies, in ONE currency (TZ 02 §3.4, §11.4): «Касса ₸ — Керуен», «Сейф $», «Kaspi QR».
 *
 * @property AccountType $type
 * @property Currency $currency
 */
class MoneyAccount extends Model
{
    use BelongsToOrganization, TracksCreator;

    /** Technical account through which trade-in offsets pass (TZ 02 §6.1). */
    public const CODE_TRADEIN_OFFSET = 'tradein_offset';

    public const CODE_EXCHANGE_OFFSET = 'exchange_offset';

    protected $fillable = ['organization_id', 'store_id', 'code', 'name', 'type', 'currency', 'default_commission_percent', 'allow_negative', 'is_hidden', 'is_active', 'sort', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'currency' => Currency::class,
            'allow_negative' => 'boolean',
            'is_hidden' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function byCode(string $code): self
    {
        return static::query()->where('code', $code)->firstOrFail();
    }
}
