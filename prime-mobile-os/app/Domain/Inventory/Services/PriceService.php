<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Inventory\Models\PriceChange;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Support\SiteChange;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Access\Limit;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Illuminate\Support\Facades\DB;

/**
 * Retail price changes with history (price_changes, append-only) and limit L14 (TZ 02 №7, TZ 03 §3.4).
 * Prices are in whole tenge.
 */
class PriceService
{
    public function __construct(private readonly AccessChecker $access) {}

    /** @param bool $showOld TZ 05 §6.2 «Показать прежнюю цену зачёркнутой» on the site */
    public function setUnitPrice(StockUnit $unit, Money $price, User $user, string $reason = 'manual', bool $showOld = false): StockUnit
    {
        $this->authorize($user, $unit->sale_price, $price->amount);

        return DB::transaction(function () use ($unit, $price, $user, $reason, $showOld) {
            $old = $unit->sale_price ?? VariantPrice::query()->where('variant_id', $unit->variant_id)->value('price_kzt');
            PriceChange::query()->create(['subject_type' => 'stock_unit', 'subject_id' => $unit->id, 'old_price' => $unit->sale_price, 'new_price' => $price->amount, 'reason' => $reason, 'user_id' => $user->id]);
            $unit->forceFill(['sale_price' => $price->amount] + ($showOld && $old > $price->amount ? ['compare_at_price' => $old] : []))->save();
            SiteChange::units([$unit->id], 'price');

            return $unit;
        });
    }

    public function setVariantPrice(int $variantId, Money $price, User $user, string $reason = 'manual'): VariantPrice
    {
        $current = VariantPrice::query()->firstOrNew(['variant_id' => $variantId]);
        $this->authorize($user, $current->exists ? $current->price_kzt : null, $price->amount);

        return DB::transaction(function () use ($current, $variantId, $price, $user, $reason) {
            PriceChange::query()->create(['subject_type' => 'variant', 'subject_id' => $variantId, 'old_price' => $current->price_kzt, 'new_price' => $price->amount, 'reason' => $reason, 'user_id' => $user->id]);
            $current->fill(['price_kzt' => $price->amount])->save();
            SiteChange::units(StockUnit::query()->where('variant_id', $variantId)->whereNull('sale_price')->whereIn('status', ['in_stock', 'reserved', 'ordered'])->pluck('id')->all(), 'price');

            return $current;
        });
    }

    public function setAccessoryPrice(int $accessoryId, Money $price, User $user, string $reason = 'manual'): AccessoryPrice
    {
        $current = AccessoryPrice::query()->firstOrNew(['accessory_id' => $accessoryId]);
        $this->authorize($user, $current->exists ? $current->price_kzt : null, $price->amount);

        return DB::transaction(function () use ($current, $accessoryId, $price, $user, $reason) {
            PriceChange::query()->create(['subject_type' => 'accessory', 'subject_id' => $accessoryId, 'old_price' => $current->price_kzt, 'new_price' => $price->amount, 'reason' => $reason, 'user_id' => $user->id]);
            $current->fill(['price_kzt' => $price->amount])->save();
            SiteChange::accessories('price');

            return $current;
        });
    }

    private function authorize(User $user, ?int $old, int $new): void
    {
        if ($new < 0 || $new % Money::TENGE !== 0) {
            throw new \InvalidArgumentException('Цена — целые тенге');
        }
        $change = $old ? Rounding::Up->divide(abs($new - $old) * 10000, $old) : 0;
        $decision = $this->access->decide($user, 'price.edit', Limit::PriceChangePercent, $change);
        if ($decision->isDenied()) {
            throw AccessDenied::because('access_denied', $decision->message);
        }
        if ($decision->needsApproval()) {
            throw new ApprovalRequired($decision, 'price_change');
        }
    }
}
