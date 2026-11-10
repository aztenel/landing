<?php

namespace App\Domain\Tenancy\Access;

/**
 * Limits of TZ 03 §5.1. Stored in role_limits (role default) and memberships.limits (personal override).
 * Value semantics (§5.2): NULL/absent = ∞, 0 = always approval. Units: money — tiyn, percent — ×100, days, flag (0/NULL).
 */
enum Limit: string
{
    case DiscountPerCheck = 'L1';
    case DiscountPercentPerCheck = 'L2';
    case DiscountPerDay = 'L3';
    case CustomerDebtTotal = 'L4';
    case CustomerDebtDays = 'L5';
    case DebtWithoutHistory = 'L6';
    case ReturnAccessory = 'L7';
    case ReturnPhone = 'L8';
    case ReturnDays = 'L9';
    case ExpenseCashPerOperation = 'L10';
    case ExpenseCashPerDay = 'L11';
    case BuyoutCash = 'L12';
    case BuyoutOverGridPercent = 'L13';
    case PriceChangePercent = 'L14';
    case WriteOffCost = 'L15';
    case CashAdjust = 'L16';
    case BonusManual = 'L17';
    case ReversalDays = 'L18';

    public function unit(): string
    {
        return match ($this) {
            self::DiscountPercentPerCheck, self::BuyoutOverGridPercent, self::PriceChangePercent => 'percent',
            self::CustomerDebtDays, self::ReturnDays, self::ReversalDays => 'days',
            self::DebtWithoutHistory => 'flag',
            default => 'money',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::DiscountPerCheck => 'Скидка на чек, ₸',
            self::DiscountPercentPerCheck => 'Скидка на чек, %',
            self::DiscountPerDay => 'Скидка за день, ₸',
            self::CustomerDebtTotal => 'Новый долг клиенту, ₸ (общий долг после продажи)',
            self::CustomerDebtDays => 'Срок долга, дней',
            self::DebtWithoutHistory => 'Долг клиенту без истории покупок',
            self::ReturnAccessory => 'Возврат аксессуара, ₸',
            self::ReturnPhone => 'Возврат телефона, ₸',
            self::ReturnDays => 'Возврат, дней после продажи',
            self::ExpenseCashPerOperation => 'Расход из кассы, ₸ за раз',
            self::ExpenseCashPerDay => 'Расходы из кассы за день, ₸',
            self::BuyoutCash => 'Выкуп б/у за наличные, ₸ за раз',
            self::BuyoutOverGridPercent => 'Выкуп дороже сетки, %',
            self::PriceChangePercent => 'Изменение розничной цены, %',
            self::WriteOffCost => 'Списание, ₸ себестоимости',
            self::CashAdjust => 'Корректировка кассы, ₸',
            self::BonusManual => 'Бонусы вручную, ₸',
            self::ReversalDays => 'Сторно, дней с проведения',
        };
    }

    /**
     * Defaults per role (TZ 03 §5.1). Missing role key = ∞ (null). Money values in tiyn, percent ×100.
     *
     * @return array<string, int|null>
     */
    public function defaults(): array
    {
        $kzt = fn (int $tenge) => $tenge * 100;
        $pct = fn (int $percent) => $percent * 100;

        return match ($this) {
            self::DiscountPerCheck => ['admin' => $kzt(30000), 'senior_seller' => $kzt(15000), 'seller' => $kzt(5000)],
            self::DiscountPercentPerCheck => ['admin' => $pct(7), 'senior_seller' => $pct(5), 'seller' => $pct(3)],
            self::DiscountPerDay => ['admin' => $kzt(150000), 'senior_seller' => $kzt(50000), 'seller' => $kzt(20000)],
            self::CustomerDebtTotal => ['admin' => $kzt(300000), 'senior_seller' => $kzt(300000), 'seller' => $kzt(100000)],
            self::CustomerDebtDays => ['admin' => 90, 'senior_seller' => 30, 'seller' => 30],
            self::DebtWithoutHistory => ['senior_seller' => 0, 'seller' => 0],
            self::ReturnAccessory => ['senior_seller' => $kzt(30000), 'seller' => 0],
            self::ReturnPhone => ['admin' => $kzt(600000), 'senior_seller' => 0, 'seller' => 0],
            self::ReturnDays => ['admin' => 14, 'senior_seller' => 14, 'seller' => 0],
            self::ExpenseCashPerOperation => ['admin' => $kzt(200000), 'senior_seller' => $kzt(20000), 'seller' => $kzt(10000)],
            self::ExpenseCashPerDay => ['admin' => $kzt(500000), 'senior_seller' => $kzt(50000), 'seller' => $kzt(20000)],
            self::BuyoutCash => ['admin' => $kzt(800000), 'senior_seller' => $kzt(300000), 'seller' => $kzt(150000)],
            self::BuyoutOverGridPercent => ['admin' => $pct(10), 'senior_seller' => $pct(5), 'seller' => 0],
            self::PriceChangePercent => ['admin' => $pct(10), 'senior_seller' => 0, 'seller' => 0],
            self::WriteOffCost => ['admin' => $kzt(50000), 'senior_seller' => 0, 'seller' => 0],
            self::CashAdjust => ['admin' => $kzt(5000), 'senior_seller' => 0, 'seller' => 0],
            self::BonusManual => ['admin' => $kzt(10000), 'senior_seller' => 0, 'seller' => 0],
            self::ReversalDays => ['admin' => 30, 'senior_seller' => 0, 'seller' => 0],
        };
    }
}
