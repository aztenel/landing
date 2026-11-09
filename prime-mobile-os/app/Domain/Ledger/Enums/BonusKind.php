<?php

namespace App\Domain\Ledger\Enums;

/** bonus_entries.kind (TZ 02 §11.2). */
enum BonusKind: string
{
    case Accrual = 'accrual';
    case Spend = 'spend';
    case ReturnOfSpent = 'return_of_spent';
    case CancelAccrual = 'cancel_accrual';
    case Expire = 'expire';
    case Manual = 'manual';
    case Opening = 'opening';
    case Reversal = 'reversal';
}
