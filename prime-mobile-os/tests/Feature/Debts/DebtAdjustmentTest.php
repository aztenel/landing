<?php

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Debts\Drafts\DebtAdjustmentDraft;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

function customerDebt($w, int $tenge, int $days): void
{
    OpeningBalanceDraft::make($w->store)->debt(CounterpartyType::Customer, $w->customer->id, Money::kzt($tenge), now()->addDays($days))->post($w->owner);
}

it('corrects a customer debt by the owner with a reason; the admin needs approval, the seller cannot', function () {
    $w = world();
    customerDebt($w, 129000, 20);

    expect(fn () => DebtAdjustmentDraft::forgive($w->customer, Money::kzt(4000))->post($w->owner))->toThrow(PostingException::class, 'основание');
    expect(fn () => DebtAdjustmentDraft::forgive($w->customer, Money::kzt(4000))->comment('скидка')->post($w->seller))->toThrow(AccessDenied::class);
    expect(fn () => DebtAdjustmentDraft::forgive($w->customer, Money::kzt(4000))->comment('скидка')->post($w->admin))->toThrow(ApprovalRequired::class);

    $doc = DebtAdjustmentDraft::forgive($w->customer, Money::kzt(4000))->comment('скидка за ожидание')->post($w->owner);
    expect($doc->number)->toStartWith('КДЛ-')->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(125000)->amount);

    // not more than the debt; a growth needs a due date in the future
    expect(fn () => DebtAdjustmentDraft::forgive($w->customer, Money::kzt(125001))->comment('x')->post($w->owner))->toThrow(PostingException::class, 'больше, чем долг');
    expect(fn () => DebtAdjustmentDraft::increase($w->customer, Money::kzt(1000), now()->subDays(2))->comment('x')->post($w->owner))->toThrow(PostingException::class, 'в прошлом');
    DebtAdjustmentDraft::increase($w->customer, Money::kzt(5000), now()->addDays(5))->comment('забыли пробить кабель')->post($w->owner);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(130000)->amount);

    app(Reverser::class)->reverse($doc, 'other', 'ошибся клиентом', $w->owner);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(134000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});

it('postpones the oldest part of a customer debt: the balance stays, the nearest due date moves', function () {
    $w = world();
    customerDebt($w, 50000, 3);
    customerDebt($w, 30000, 20);
    expect($w->debt('customer', $w->customer->id)->nearest_due_date->toDateString())->toBe(now()->addDays(3)->toDateString());

    // a seller — only by approval (TZ 03 право «Перенести срок долга»)
    expect(fn () => DebtAdjustmentDraft::postpone($w->customer, Money::kzt(50000), now()->addDays(25))->comment('зарплата позже')->post($w->seller))
        ->toThrow(ApprovalRequired::class);
    $doc = DebtAdjustmentDraft::postpone($w->customer, Money::kzt(50000), now()->addDays(25))->comment('зарплата позже')->post($w->admin);
    $debt = $w->debt('customer', $w->customer->id);
    expect((int) $debt->balance)->toBe(Money::kzt(80000)->amount)
        ->and($debt->nearest_due_date->toDateString())->toBe(now()->addDays(20)->toDateString())
        ->and(\App\Domain\Debts\Models\DebtAdjustment::query()->where('document_id', $doc->id)->first()->previous_due_date->toDateString())
        ->toBe(now()->addDays(3)->toDateString());

    expect(fn () => DebtAdjustmentDraft::postpone($w->customer, Money::kzt(80001), now()->addDays(5))->comment('x')->post($w->owner))
        ->toThrow(PostingException::class, 'больше, чем долг');
    expect(Reconciliation::problems())->toBe([]);
});
