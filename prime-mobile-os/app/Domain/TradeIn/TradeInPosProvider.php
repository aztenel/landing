<?php

namespace App\Domain\TradeIn;

use App\Domain\Pos\Contracts\TradeInProvider;
use App\Domain\Pos\Models\Cart;
use App\Filament\TradeIn\Pages\NewAct;

/** F8 on the cash register opens the act in the mode «в зачёт» of this check (docs/modules/pos.md). */
final class TradeInPosProvider implements TradeInProvider
{
    public function available(): bool
    {
        return \App\Support\Ui\Access::allows('tradein.create');
    }

    public function startUrl(Cart $cart): string
    {
        return NewAct::getUrl(['tab' => $cart->tab]);
    }
}
