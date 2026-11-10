<?php

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\NotImplementedDocumentType;
use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\HandlerRegistry;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Ledger\Services\AccountingPeriods;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Idempotency\Idempotency;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;

it('posts once per idempotency key: a second «Провести» returns the same document (TZ 02 §14 №10)', function () {
    $w = world();
    $unit = $w->phone();
    $key = Idempotency::newKey();
    $build = fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->idempotencyKey($key);

    $first = $build()->post($w->seller);
    $second = $build()->post($w->seller);                 // the form was submitted again
    $third = app(Poster::class)->post($first, $w->seller); // the same draft posted again

    expect($second->id)->toBe($first->id)->and($third->number)->toBe($first->number)
        ->and(Document::query()->where('type', 'sale')->count())->toBe(1)
        ->and(MoneyEntry::query()->where('document_id', $first->id)->count())->toBe(1)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(549000)->amount);
});

it('numbers documents without gaps per organization, type and year', function () {
    $w = world();
    $year = now('Asia/Almaty')->year;
    $a = $w->cash(Money::kzt(1));
    $failed = OpeningBalanceDraft::make($w->store)->money($w->account('cash_kzt'), Money::kzt(-100))->save();
    expect(fn () => app(Poster::class)->post($failed, $w->owner))->toThrow(PostingException::class);
    $b = $w->cash(Money::kzt(1));

    expect($a->number)->toBe("ВХО-{$year}-000001")->and($b->number)->toBe("ВХО-{$year}-000002");

    $other = world(name: 'Второй филиал');
    expect($other->cash(Money::kzt(1))->number)->toBe("ВХО-{$year}-000001");
});

it('refuses to post into a closed period and dates the reversal of a closed-period document today (TZ 02 §9, §14 №7)', function () {
    $w = world();
    $lastMonth = CarbonImmutable::now('Asia/Almaty')->subMonthNoOverflow()->startOfMonth()->addDays(3)->setTime(12, 0);

    // a document of last month (posted back then)
    CarbonImmutable::setTestNow($lastMonth);
    $old = $w->cash(Money::kzt(1000));
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();

    app(AccountingPeriods::class)->close($lastMonth, $w->owner);
    $draftInPast = OpeningBalanceDraft::make($w->store)->money($w->account('cash_kzt'), Money::kzt(500))->occurredAt($lastMonth)->reason('забыли')->save();
    expect(CurrentOrganization::instance()->require()->closed_until->format('Y-m-d'))->toBe($lastMonth->endOfMonth()->format('Y-m-d'));

    expect(fn () => app(Poster::class)->post($draftInPast, $w->owner))->toThrow(PeriodClosed::class, 'Период закрыт');

    // the database refuses too, even if the application check is bypassed
    try {
        DB::transaction(fn () => DB::update("UPDATE documents SET status = 'posted', number = 'ВХО-0000-999999', number_year = 2000 WHERE id = ?", [$draftInPast->id]));
        $this->fail('trigger expected');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('Период закрыт');
    }

    $reversal = app(Reverser::class)->reverse($old, ReversalReason::InputError, null, $w->owner);
    expect($reversal->occurred_at->isToday())->toBeTrue()
        ->and(MoneyEntry::query()->where('document_id', $reversal->id)->first()->occurred_at->isToday())->toBeTrue();
});

it('does not close the current month nor let a seller close a period', function () {
    $w = world();
    expect(fn () => app(AccountingPeriods::class)->close(now(), $w->owner))->toThrow(PostingException::class, 'прошедший')
        ->and(fn () => app(AccountingPeriods::class)->close(now()->subMonth(), $w->seller))->toThrow(AccessDenied::class);
});

it('allows backdating only to admins/owners, up to 3 days, with a reason', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = fn ($user, $days, $reason = null) => SaleDraft::make($w->store, $user)->accessory($w->case, 1, Money::kzt(9000))
        ->cash(Money::kzt(9000))->occurredAt(now()->subDays($days))->reason($reason)->post($user);

    expect(fn () => $sale($w->seller, 1, 'забыли провести'))->toThrow(PostingException::class, 'админ и владелец')
        ->and(fn () => $sale($w->admin, 1))->toThrow(PostingException::class, 'причину')
        ->and(fn () => $sale($w->admin, 5, 'забыли'))->toThrow(PostingException::class, 'не раньше');
    expect($sale($w->admin, 2, 'забыли провести')->isPosted())->toBeTrue();
});

it('reports a clear error for document types whose module is not built yet', function () {
    $w = world();
    $doc = new Document;
    $doc->forceFill(['type' => DocumentType::LegacyReturn->value, 'store_id' => $w->store->id, 'occurred_at' => now(), 'idempotency_key' => Idempotency::newKey()])->save();

    expect(fn () => app(Poster::class)->post($doc, $w->owner))->toThrow(NotImplementedDocumentType::class, 'ещё не реализовано');

    $registry = app(HandlerRegistry::class);
    expect($registry->has(DocumentType::Sale))->toBeTrue()
        ->and($registry->has(DocumentType::DebtPaymentOut))->toBeTrue()
        ->and($registry->has(DocumentType::LegacyReturn))->toBeFalse();
});

it('takes the opening balance (day X): phones, accessories, money, debts, bonuses', function () {
    $w = world();
    $w->rate('512.40');
    $doc = OpeningBalanceDraft::make($w->store)
        ->unit($w->iphone, World::imei(), Money::usd(1140), ['supplier_id' => $w->supplier->id])
        ->unit($w->usedIphone, World::imei(), Money::kzt(165000), ['condition' => 'used', 'grade' => 'A', 'battery_percent' => 89, 'status_after' => 'preparing'])
        ->accessory($w->case, 12, Money::kzt(2400))
        ->money($w->account('cash_kzt'), Money::kzt(1240000))
        ->money($w->account('cash_usd'), Money::usd(2400))
        ->customerDebt($w->customer, Money::kzt(70000), now()->subDays(3))
        ->supplierDebt($w->supplier, Money::usd(5000), now()->addDays(20), '510')
        ->bonus($w->customer, 2500)
        ->post($w->owner);

    expect($doc->number)->toStartWith('ВХО-')
        ->and(StockUnit::query()->where('status', 'in_stock')->value('cost_kzt'))->toBe(Money::kzt(584136)->amount)
        ->and(StockUnit::query()->where('status', 'preparing')->count())->toBe(1)
        ->and($w->accessoryQty($w->case))->toBe(12)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(1240000)->amount)
        ->and($w->balance('cash_usd'))->toBe(Money::usd(2400)->amount)
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(70000)->amount)
        ->and((int) $w->debt('supplier', $w->supplier->id, 'USD')->balance_kzt)->toBe(-Money::kzt(2550000)->amount)
        ->and((int) $w->bonuses($w->customer)->available)->toBe(2500);

    // the overdue customer debt blocks a new credit sale by a seller (TZ 03 §5.2 p.8)
    $unit = $w->phone();
    expect(fn () => SaleDraft::make($w->store, $w->admin)->customer($w->customer)->unit($unit, Money::kzt(549000))
        ->cash(Money::kzt(500000))->debt(Money::kzt(49000), now()->addDays(5))->post($w->admin))
        ->toThrow(ApprovalRequired::class, 'просроченный');
});
