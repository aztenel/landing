<?php

namespace App\Domain\Ledger\Posting;

/** Does a document need an open shift of its store (audit №18, docs/audit/decisions.md)? */
enum ShiftRequirement: string
{
    /** sale, customer return, legacy return, trade-in, order with prepayment */
    case Always = 'always';
    /** only when it moves the cash of a store (cash account with a store): expense, debt payments, purchase … */
    case IfStoreCash = 'if_store_cash';
    /** opening balance, cash adjustment РКС, owner's receipt of a collection, non-cash and stock documents, nightly jobs */
    case Never = 'never';
}
