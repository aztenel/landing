<?php

namespace App\Domain\Ledger\Enums;

/** inventory_movements.kind (TZ 02 §11.2). */
enum MovementKind: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case ReturnIn = 'return_in';
    case TradeInIn = 'tradein_in';
    case PrepCost = 'prep_cost';
    case CostAdjustment = 'cost_adjustment';
    case CostEntry = 'cost_entry';
    case Transfer = 'transfer';
    case WriteOff = 'write_off';
    case SupplierReturn = 'supplier_return';
    case Surplus = 'surplus';
    case Shortage = 'shortage';
    case Opening = 'opening';
    case Reversal = 'reversal';
}
