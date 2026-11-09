<?php

namespace App\Domain\Inventory\Enums;

/**
 * The ONLY list of unit statuses for all TZ sections (TZ 02 §10.1). A stock_units.status of NULL means
 * «нет в учёте» — the unit's creation (purchase / opening) was reversed («—» in the transition table).
 */
enum UnitStatus: string
{
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Ordered = 'ordered';
    case InTransit = 'in_transit';
    case Preparing = 'preparing';
    case OnCheck = 'on_check';
    case Defective = 'defective';
    case Sold = 'sold';
    case ReturnedToSupplier = 'returned_to_supplier';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наличии',
            self::Reserved => 'В корзине',
            self::Ordered => 'Забронирован',
            self::InTransit => 'В пути',
            self::Preparing => 'На подготовке',
            self::OnCheck => 'На проверке',
            self::Defective => 'Брак',
            self::Sold => 'Продан',
            self::ReturnedToSupplier => 'Возвращён поставщику',
            self::WrittenOff => 'Списан',
        };
    }

    /** May be shown on the storefront (if «показывать на сайте» and the IMEI registry check is fine). */
    public function visibleOnSite(): bool
    {
        return in_array($this, [self::InStock, self::Reserved, self::Ordered], true);
    }

    /** Physically present in a store of the organization. */
    public function isOnHand(): bool
    {
        return in_array($this, [self::InStock, self::Reserved, self::Ordered, self::Preparing, self::OnCheck, self::Defective], true);
    }

    public static function labelOf(?self $status): string
    {
        return $status?->label() ?? 'Нет в учёте';
    }
}
