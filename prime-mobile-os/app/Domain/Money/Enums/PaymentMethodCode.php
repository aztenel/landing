<?php

namespace App\Domain\Money\Enums;

/**
 * Payment methods of a sale (TZ 02 §11.1 sale_payments.method).
 */
enum PaymentMethodCode: string
{
    case Cash = 'cash';
    case Card = 'card';
    case KaspiQr = 'kaspi_qr';
    case KaspiTransfer = 'kaspi_transfer';
    case KaspiRed = 'kaspi_red';
    case KaspiInstallment = 'kaspi_installment';
    case HalykInstallment = 'halyk_installment';
    case HomeCreditInstallment = 'homecredit_installment';
    case FreedomInstallment = 'freedom_installment';
    case Debt = 'debt';
    case Bonus = 'bonus';
    case TradeIn = 'tradein';
    case Prepayment = 'prepayment';
    case KaspiShop = 'kaspi_shop';            // Kaspi Магазин: the whole order with this one method (audit №34)
    case ExchangeOffset = 'exchange_offset';  // Зачёт возврата при обмене: only with the return in one operation (audit №34)

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Наличные',
            self::Card => 'Карта',
            self::KaspiQr => 'Kaspi QR',
            self::KaspiTransfer => 'Kaspi перевод',
            self::KaspiRed => 'Kaspi Red',
            self::KaspiInstallment => 'Kaspi рассрочка',
            self::HalykInstallment => 'Halyk рассрочка',
            self::HomeCreditInstallment => 'Home Credit рассрочка',
            self::FreedomInstallment => 'Freedom рассрочка',
            self::Debt => 'В долг',
            self::Bonus => 'Бонусы',
            self::TradeIn => 'Зачёт trade-in',
            self::Prepayment => 'Из предоплаты',
            self::KaspiShop => 'Kaspi Магазин',
            self::ExchangeOffset => 'Зачёт возврата (обмен)',
        };
    }

    /** Writes a money entry to a real account (with optional bank commission). */
    public function isMoney(): bool
    {
        return ! in_array($this, [self::Debt, self::Bonus, self::TradeIn, self::Prepayment, self::ExchangeOffset], true);
    }

    /** Bank methods: switched on only after the owner entered the commission percent (audit №52). */
    public function hasBankCommission(): bool
    {
        return in_array($this, [self::Card, self::KaspiQr, self::KaspiRed, self::KaspiInstallment, self::HalykInstallment,
            self::HomeCreditInstallment, self::FreedomInstallment, self::KaspiShop], true);
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}
