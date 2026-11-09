<?php

namespace App\Domain\Counterparties\Enums;

enum CounterpartyType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
}
