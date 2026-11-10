<?php

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Money\Drafts\CashMoveDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

it('sends the takings through «Инкассация в пути»; the owner accepts another sum, the difference is on the sender (audit №28)', function () {
    $w = world();
    $w->cash(Money::kzt(700000));

    $sent = CashMoveDraft::collection($w->store, $w->account('cash_kzt'), Money::kzt(600000))->post($w->seller);
    expect($sent->number)->toStartWith('ДДС-')
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(100000)->amount)
        ->and($w->balance('collection_transit_kzt'))->toBe(Money::kzt(600000)->amount);

    // only the owner accepts
    expect(fn () => CashMoveDraft::acceptCollection($w->store, $sent, Money::kzt(600000))->post($w->admin))->toThrow(AccessDenied::class);

    CashMoveDraft::acceptCollection($w->store, $sent, Money::kzt(598000))->note('пересчитал дома')->post($w->owner);
    expect($w->balance('collection_transit_kzt'))->toBe(0)
        ->and($w->balance('safe_kzt'))->toBe(Money::kzt(598000)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    // an accepted collection cannot be accepted twice
    expect(fn () => CashMoveDraft::acceptCollection($w->store, $sent, Money::kzt(1))->post($w->owner))->toThrow(PostingException::class, 'уже принята');
});

it('lets only the owner reject a collection (reversal of the sending) and take money out of the cash', function () {
    $w = world();
    $w->cash(Money::kzt(100000));
    $sent = CashMoveDraft::collection($w->store, $w->account('cash_kzt'), Money::kzt(80000))->post($w->seller);

    expect(fn () => app(Reverser::class)->reverse($sent, 'other', 'отклоняю инкассацию', $w->admin))->toThrow(AccessDenied::class);
    app(Reverser::class)->reverse($sent, 'other', 'отклоняю инкассацию', $w->owner);
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(100000)->amount)->and($w->balance('collection_transit_kzt'))->toBe(0);

    expect(fn () => CashMoveDraft::withdrawal($w->store, $w->account('cash_kzt'), Money::kzt(10000))->post($w->admin))
        ->toThrow(\App\Domain\Tenancy\Access\Exceptions\ApprovalRequired::class);
    CashMoveDraft::withdrawal($w->store, $w->account('cash_kzt'), Money::kzt(10000))->post($w->owner);
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(90000)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('transfers between accounts of one currency with a bank fee; a collection cannot exceed the cash', function () {
    $w = world();
    $w->cash(Money::kzt(50000), 'kaspi_gold');
    CashMoveDraft::transfer($w->store, $w->account('kaspi_gold'), $w->account('safe_kzt'), Money::kzt(40000), Money::kzt(200))->post($w->admin);
    expect($w->balance('kaspi_gold'))->toBe(Money::kzt(9800)->amount)->and($w->balance('safe_kzt'))->toBe(Money::kzt(40000)->amount);

    expect(fn () => CashMoveDraft::collection($w->store, $w->account('cash_kzt'), Money::kzt(1000))->post($w->seller))
        ->toThrow(\App\Domain\Ledger\Exceptions\InsufficientFunds::class);
});
