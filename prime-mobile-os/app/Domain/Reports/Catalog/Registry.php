<?php

namespace App\Domain\Reports\Catalog;

/** The catalog О1–О28 (TZ 06 §3), in tab order. О10 (оборачиваемость) needs the daily stock history — later. */
final class Registry
{
    public const REPORTS = [
        SalesProfit::class, BySeller::class, ByModel::class, BySource::class, ByPayment::class, Returns::class, Discounts::class,
        StockValue::class, Aging::class, RunningLow::class, UsedReport::class, Purchases::class, Movements::class,
        MoneyMoves::class, ExpensesReport::class, Shifts::class, FxReport::class,
        CustomerDebts::class, SupplierDebts::class,
        Bonuses::class, Customers::class, TradeIns::class,
        Reversals::class, Questionable::class, Warranty::class, SiteReport::class, Audit::class,
    ];

    /** @return list<Report> */
    public static function all(): array
    {
        return array_map(fn ($c) => app($c), self::REPORTS);
    }

    /** @return list<Report> */
    public static function allowed(): array
    {
        return array_values(array_filter(self::all(), fn (Report $r) => $r->allowed()));
    }

    public static function find(string $key): ?Report
    {
        foreach (self::all() as $r) {
            if ($r->key() === $key) {
                return $r;
            }
        }

        return null;
    }
}
