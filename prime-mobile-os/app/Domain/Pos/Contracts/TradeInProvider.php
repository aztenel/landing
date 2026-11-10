<?php

namespace App\Domain\Pos\Contracts;

use App\Domain\Pos\Models\Cart;

/**
 * Point where the trade-in module plugs into the cash register (docs/modules/pos.md). Until the module binds its own
 * implementation, NullTradeInProvider answers «not available» and F8 shows «Trade-in подключается».
 *
 * The module: binds this interface; startUrl() opens the act (Э6) for this cart; when the act is ready it adds the
 * offset to the check through the payment method «tradein» (PaymentMethodCode::TradeIn) with the act's account —
 * CartService::setPayments() refuses that method on the cash register itself, the module uses its own entry point.
 */
interface TradeInProvider
{
    public function available(): bool;

    public function startUrl(Cart $cart): string;
}
