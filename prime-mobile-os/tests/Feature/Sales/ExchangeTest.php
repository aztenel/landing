<?php

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Services\Exchange;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

it('exchanges a phone for a dearer one in one operation; «Зачёт обмена» is 0 after the pair (audit №34, scenario 31)', function () {
    $w = world();
    $old = $w->phone(cost: Money::kzt(400000));
    $new = $w->phone(cost: Money::kzt(400000));
    $new->forceFill(['sale_price' => Money::kzt(560000)->amount])->save();
    $w->cash(Money::kzt(100000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($old, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->first();

    $return = CustomerReturnDraft::make($sale)->line($line)->refundToExchange(Money::kzt(549000))->save();
    $buy = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($new, Money::kzt(560000))
        ->pay('exchange_offset', Money::kzt(549000))->cash(Money::kzt(11000))->save();
    [$r, $s] = app(Exchange::class)->post($return, $buy, $w->owner);

    expect($r->isPosted())->toBeTrue()->and($s->isPosted())->toBeTrue()
        ->and($r->exchange_group_id)->toBe($s->exchange_group_id)
        ->and($w->balance('exchange_offset'))->toBe(0)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(100000 + 549000 + 11000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});

it('refuses the offset without a return of the same operation or with another amount', function () {
    $w = world();
    $unit = $w->phone();
    expect(fn () => SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->pay('exchange_offset', Money::kzt(549000))->post($w->owner))
        ->toThrow(PostingException::class, 'только при обмене');

    $old = $w->phone();
    $sale = SaleDraft::make($w->store, $w->owner)->unit($old, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $return = CustomerReturnDraft::make($sale)->line(SaleLine::query()->where('document_id', $sale->id)->first())->refundToExchange(Money::kzt(549000))->save();
    $buy = SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->pay('exchange_offset', Money::kzt(500000))->cash(Money::kzt(49000))->save();
    expect(fn () => app(Exchange::class)->post($return, $buy, $w->owner))->toThrow(PostingException::class, 'не равен');
    expect(Document::query()->where('type', 'customer_return')->where('status', 'posted')->count())->toBe(0); // all or nothing
});
