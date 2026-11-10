<?php

namespace App\Domain\Sales\Services;

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * «Обмен» (TZ 01 П11.5 Б, audit №34): the return and the new sale are posted as ONE operation, the return first.
 * The return gives «Зачёт возврата» (refundToExchange), the new sale takes it as the payment «exchange_offset»;
 * the technical account «Зачёт обмена» is 0 after the pair. A dearer new item — the rest is paid as usual; a cheaper
 * one — the rest of the return is refunded by the usual rule (debt first, then the methods of the sale).
 *
 *   $return = CustomerReturnDraft::make($sale)->line($line)->refundToExchange(Money::kzt(100000))->save();
 *   $new = SaleDraft::make($store, $seller)->customer($c)->unit($unit, Money::kzt(129000))
 *       ->pay('exchange_offset', Money::kzt(100000))->cash(Money::kzt(29000))->save();
 *   [$returnDoc, $saleDoc] = app(Exchange::class)->post($return, $new, $seller);
 */
class Exchange
{
    public function __construct(private readonly Poster $poster) {}

    /** @return array{0: Document, 1: Document} */
    public function post(Document $returnDraft, Document $saleDraft, User $actor): array
    {
        if ($returnDraft->type->value !== 'customer_return' || $saleDraft->type->value !== 'sale') {
            throw PostingException::because('bad_exchange', 'Обмен — это возврат и новая продажа');
        }
        $group = $returnDraft->exchange_group_id ?? $saleDraft->exchange_group_id ?? (string) Str::uuid();
        foreach ([$returnDraft, $saleDraft] as $d) {
            if ($d->isDraft() && $d->exchange_group_id !== $group) {
                $d->forceFill(['exchange_group_id' => $group])->save();
            }
        }

        return $this->poster->postMany([$returnDraft->refresh(), $saleDraft->refresh()], $actor);
    }
}
