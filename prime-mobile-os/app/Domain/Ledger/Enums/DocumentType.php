<?php

namespace App\Domain\Ledger\Enums;

use App\Support\DocumentNumber\DocumentNumber;

/**
 * All document types of TZ 02 §11.1. Each posted type has a DocumentHandler registered in HandlerRegistry.
 */
enum DocumentType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case CustomerReturn = 'customer_return';
    case TradeIn = 'tradein';
    case PrepCost = 'prep_cost';
    case UnitEdit = 'unit_edit';
    case CostAdjustment = 'cost_adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case WriteOff = 'write_off';
    case SupplierReturn = 'supplier_return';
    case Revision = 'revision';
    case Expense = 'expense';
    case DebtPaymentIn = 'debt_payment_in';
    case DebtPaymentOut = 'debt_payment_out';
    case DebtAdjustment = 'debt_adjustment';
    case CashMove = 'cash_move';
    case FxConvert = 'fx_convert';
    case CashAdjustment = 'cash_adjustment';   // РКС: cash difference at shift open/close or manual (audit №57)
    case CostEntry = 'cost_entry';             // ВСБ: purchase cost entered later (audit №4)
    case LegacyReturn = 'legacy_return';       // ВДП: return of a phone sold before the switch from sfon (audit №3)
    case BonusExpire = 'bonus_expire';         // БСГ: nightly system document (audit №54)
    case BonusManual = 'bonus_manual';
    case Order = 'order';
    case OpeningBalance = 'opening_balance';
    case Reversal = 'reversal';

    /** Number prefix = counter series (TZ 02 §2.3). */
    public function prefix(): string
    {
        return DocumentNumber::prefixFor($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Приход',
            self::Sale => 'Продажа',
            self::CustomerReturn => 'Возврат покупателя',
            self::TradeIn => 'Акт trade-in',
            self::PrepCost => 'Затраты на подготовку',
            self::UnitEdit => 'Исправление карточки',
            self::CostAdjustment => 'Корректировка себестоимости',
            self::TransferOut => 'Перемещение: отправка',
            self::TransferIn => 'Перемещение: приём',
            self::WriteOff => 'Списание',
            self::SupplierReturn => 'Возврат поставщику',
            self::Revision => 'Ревизия',
            self::Expense => 'Расход',
            self::DebtPaymentIn => 'Оплата долга клиентом',
            self::DebtPaymentOut => 'Оплата поставщику',
            self::DebtAdjustment => 'Корректировка долга',
            self::CashMove => 'Движение денег',
            self::FxConvert => 'Конвертация валюты',
            self::CashAdjustment => 'Расхождение кассы',
            self::CostEntry => 'Внесение себестоимости',
            self::LegacyReturn => 'Возврат по продаже до перехода',
            self::BonusExpire => 'Сгорание бонусов',
            self::BonusManual => 'Бонусы вручную',
            self::Order => 'Заказ',
            self::OpeningBalance => 'Входящие остатки',
            self::Reversal => 'Сторно',
        };
    }
}
