<?php

namespace App\Domain\Ledger\Enums;

/** debt_entries.kind (TZ 02 §11.2). Sign: + counterparty owes us more, − we owe more (§6.1). */
enum DebtKind: string
{
    case SaleCredit = 'sale_credit';
    case PaymentIn = 'payment_in';
    case PurchaseCredit = 'purchase_credit';
    case PaymentOut = 'payment_out';
    case Prepayment = 'prepayment';
    case PrepaymentUse = 'prepayment_use';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case FxDiff = 'fx_diff';
    case Opening = 'opening';
    case Reversal = 'reversal';

    public function requiresDueDate(): bool
    {
        return $this === self::SaleCredit || $this === self::PurchaseCredit;
    }
}
