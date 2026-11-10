<?php

namespace App\Domain\Money\Enums;

/**
 * System money articles (TZ 02 §11.4) and the default expense articles of a new organization.
 */
enum ArticleCode: string
{
    // system
    case Revenue = 'revenue';
    case BankFee = 'bank_fee';
    case Buyout = 'buyout';
    case SupplierPayment = 'supplier_payment';
    case CustomerRefund = 'customer_refund';
    case Transfer = 'transfer';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case FxConvert = 'fx_convert';
    case CashDiff = 'cash_diff';
    case SupplierReturnDiff = 'supplier_return_diff';
    case DebtIn = 'debt_in';
    case Prepayment = 'prepayment';
    case Opening = 'opening';
    // expenses (default set, organizations add their own)
    case Rent = 'rent';
    case Salary = 'salary';
    case Courier = 'courier';
    case Ads = 'ads';
    case RepairAfterSale = 'repair_after_sale';
    case WarrantyRepair = 'warranty_repair';
    case Communication = 'communication';
    case Household = 'household';
    case Taxes = 'taxes';

    /** @return array{0: string, 1: string, 2: bool} [name_ru, kind, affects_profit] */
    public function definition(): array
    {
        return match ($this) {
            self::Revenue => ['Выручка', 'income', true],
            self::BankFee => ['Комиссия банка', 'expense', true],
            self::Buyout => ['Выкуп б/у', 'system', false],
            self::SupplierPayment => ['Оплата поставщику', 'system', false],
            self::CustomerRefund => ['Возврат покупателю', 'system', true],
            self::Transfer => ['Перевод', 'system', false],
            self::Deposit => ['Внесение', 'system', false],
            self::Withdrawal => ['Изъятие владельцем', 'system', false],
            self::FxConvert => ['Конвертация валюты', 'system', false],
            self::CashDiff => ['Недостача/излишек кассы', 'system', true],
            self::SupplierReturnDiff => ['Разница при возврате поставщику', 'system', true],
            self::DebtIn => ['Оплата долга клиентом', 'system', false],
            self::Prepayment => ['Предоплата клиента', 'system', false],
            self::Opening => ['Входящие остатки', 'system', false],
            self::Rent => ['Аренда', 'expense', true],
            self::Salary => ['Зарплата', 'expense', true],
            self::Courier => ['Доставка курьером', 'expense', true],
            self::Ads => ['Реклама', 'expense', true],
            self::RepairAfterSale => ['Ремонт после продажи', 'expense', true],
            self::WarrantyRepair => ['Гарантийный ремонт', 'expense', true],
            self::Communication => ['Связь', 'expense', true],
            self::Household => ['Хозтовары', 'expense', true],
            self::Taxes => ['Налоги', 'expense', true],
        };
    }
}
