<?php

namespace App\Domain\Pos\Contracts;

use App\Domain\Pos\Models\Cart;

final class NullTradeInProvider implements TradeInProvider
{
    public function available(): bool
    {
        return false;
    }

    public function startUrl(Cart $cart): string
    {
        return '';
    }
}
