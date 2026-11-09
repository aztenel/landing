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
        };
    }

    /** Writes a money entry to a real account (with optional bank commission). */
    public function isMoney(): bool
    {
        return ! in_array($this, [self::Debt, self::Bonus, self::TradeIn, self::Prepayment], true);
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}
