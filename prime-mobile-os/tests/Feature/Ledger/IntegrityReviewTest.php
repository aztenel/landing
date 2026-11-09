<?php

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\UnitStatusService;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\HandlerRegistry;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Drafts\FxConvertDraft;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Services\ReturnCalculator;
use App\Support\Idempotency\Idempotency;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Reconciliation;
use Tests\Support\World;

/*
 | Integrity review of the core (TZ 02): copies = journals after every handler and every reversal, exact
 | restoration on reversal, rounding of partial returns, locks, idempotency, DB guards.
 */

function rev(Document $doc, World $w): Document
{
    return app(Reverser::class)->reverse($doc, ReversalReason::InputError, null, $w->owner);
}

/** Snapshot of all balance copies of the current organization (to compare before / after reversals). */
function copiesSnapshot(): array
{
    $org = CurrentOrganization::instance()->requireId();

    return [
        'accounts' => DB::table('account_balances')->where('organization_id', $org)->where('balance', '<>', 0)->orderBy('account_id')->pluck('balance', 'account_id')->all(),
        'accessories' => DB::table('accessory_balances')->where('organization_id', $org)->where('qty', '<>', 0)->orderBy('id')->get(['accessory_id', 'location_id', 'qty'])->map(fn ($r) => (array) $r)->all(),
        'avg' => DB::table('accessory_costs')->where('organization_id', $org)->orderBy('accessory_id')->get(['accessory_id', 'total_qty', 'avg_cost_kzt'])->map(fn ($r) => (array) $r)->all(),
        'debts' => DB::table('counterparty_balances')->where('organization_id', $org)->where('balance', '<>', 0)->orderBy('id')->get(['counterparty_type', 'counterparty_id', 'currency', 'balance', 'balance_kzt'])->map(fn ($r) => (array) $r)->all(),
        'bonuses' => DB::table('customer_bonus_balances')->where('organization_id', $org)->orderBy('customer_id')->get(['customer_id', 'available', 'pending'])->map(fn ($r) => (array) $r)->all(),
        'units' => DB::table('stock_units')->where('organization_id', $org)->whereNotNull('status')->orderBy('id')->get(['id', 'status', 'location_id', 'cost_kzt'])->map(fn ($r) => (array) $r)->all(),
    ];
}

it('keeps every balance copy equal to its journal through all core handlers and their reversals (TZ 02 §13.2)', function () {
    $w = world(['bonus' => ['activation_days' => 0]]);
    $w->rate('515');
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);

    OpeningBalanceDraft::make($w->store)
        ->accessory($w->case, 10, Money::kzt(2000))
        ->money($w->account('cash_kzt'), Money::kzt(3000000))
        ->money($w->account('cash_usd'), Money::usd(5000))
        ->supplierDebt($w->supplier, Money::usd(10000), now()->addDays(20), '515')
        ->customerDebt($w->customer, Money::kzt(70000), now()->addDays(10))
        ->bonus($w->customer, 1000, now()->subDay(), now()->addYear())
        ->post($w->owner);
    $phone = $w->phone();
    expect(Reconciliation::problems())->toBe([]);
    $before = copiesSnapshot();

    $imei = World::imei();
    $purchase = PurchaseDraft::make($w->store, $w->supplier, Currency::USD)
        ->unit($w->iphone, $imei, Money::usd(950))
        ->accessory($w->case, 20, Money::usd('5.05'))
        ->payFrom($w->account('cash_usd'), Money::usd(500))
        ->payFrom($w->account('cash_kzt'), Money::kzt(51500))
        ->onCredit(now()->addDays(30))
        ->post($w->owner);
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)
        ->unit($phone, Money::kzt(549000))->accessory($w->case, 3, Money::kzt(9000))->discount(Money::kzt(3001 - 1))
        ->bonus(Money::kzt(1000))->pay('kaspi_qr', Money::kzt(200000))->debt(Money::kzt(100000), now()->addDays(10))
        ->cash(Money::kzt(549000 + 27000 - 3000 - 1000 - 200000 - 100000), Money::kzt(275000))
        ->post($w->owner);
    $caseLine = SaleLine::query()->where('document_id', $sale->id)->whereNotNull('accessory_id')->firstOrFail();
    $return = CustomerReturnDraft::make($sale)->line($caseLine, 1)->save();
    $quote = app(ReturnCalculator::class)->quote($return);
    expect($quote['to_debt_min'])->toBeGreaterThan(0); // part of the check was on credit
    $return = CustomerReturnDraft::make($sale)->line($caseLine, 1)->refundToDebt(Money::kztMinor($quote['to_debt_min']))
        ->refund('cash', Money::kztMinor($quote['refund'] - $quote['to_debt_min']))->post($w->owner);
    $expense = ExpenseDraft::make(ArticleCode::Rent, $w->store)->pay($w->account('cash_kzt'), Money::kzt(50000))
        ->onCredit($w->supplier, Money::kzt(10000), now()->addDays(5))->post($w->owner);
    $payIn = DebtPaymentDraft::fromCustomer($w->customer, $w->account('kaspi_qr'), Money::kzt(30000), $w->store, '1.00')->post($w->owner);
    $w->rate('520');
    $payOut = DebtPaymentDraft::toSupplier($w->supplier, $w->account('cash_kzt'), Money::kzt(2080000), Currency::USD, Money::usd(4000), $w->store)->post($w->owner);
    $fx = FxConvertDraft::make($w->account('cash_usd'), Money::usd(1000), $w->account('cash_kzt'), Money::kzt(512000), $w->store)->post($w->owner);

    expect(Reconciliation::problems())->toBe([]);

    // reverse everything (dependants first): the copies come back exactly to the state before
    foreach ([$fx, $payOut, $payIn, $expense, $return, $sale, $purchase] as $doc) {
        rev($doc, $w);
        expect(Reconciliation::problems())->toBe([]);
    }
    expect(copiesSnapshot())->toBe($before);
});

it('refuses a payment «из аванса» larger than the customer advance (TZ 02 §6.2 №16)', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));

    // no advance at all: the sale must not silently become a debt without due date and credit checks
    expect(fn () => SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))
        ->pay('prepayment', Money::kzt(9000))->post($w->owner))->toThrow(PostingException::class, 'аванс');
    expect($w->debt('customer', $w->customer->id)?->balance ?? 0)->toBe(0);

    // with an advance of 5 000 ₸ (we owe the customer) a check of 9 000 can use at most 5 000
    OpeningBalanceDraft::make($w->store)->debt(CounterpartyType::Customer, $w->customer->id, Money::kzt(-5000))->post($w->owner);
    expect(fn () => SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))
        ->pay('prepayment', Money::kzt(9000))->post($w->owner))->toThrow(PostingException::class, 'аванс');
    SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))
        ->pay('prepayment', Money::kzt(5000))->cash(Money::kzt(4000))->post($w->owner);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});

it('returns bonuses of partial returns exactly: Σ restored = spent, Σ cancelled = accrued (cumulative rounding)', function () {
    $w = world(['bonus' => ['accessory_percent' => '3.00', 'activation_days' => 0]]);
    OpeningBalanceDraft::make($w->store)->bonus($w->customer, 50, now()->subDay(), now()->addYear())->post($w->owner);
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 3, Money::kzt(10000))
        ->bonus(Money::kzt(50))->cash(Money::kzt(29950))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    expect($line->bonus_accrued)->toBe(898)                      // 3 % of 29 950 = 898,5 → 898
        ->and((int) $w->bonuses($w->customer)->available)->toBe(898);

    $returned = 0;
    $cancelled = 0;
    $refunded = 0;
    foreach ([1, 1, 1] as $qty) {
        $draft = CustomerReturnDraft::make($sale)->line($line->refresh(), $qty)->save();
        $quote = app(ReturnCalculator::class)->quote($draft);
        $doc = CustomerReturnDraft::make($sale)->line($line, $qty)->refund('cash', Money::kztMinor($quote['refund']))->post($w->owner);
        $header = CustomerReturn::query()->where('document_id', $doc->id)->first();
        $returned += $header->bonus_returned;
        $cancelled += $header->bonus_cancelled;
        $refunded += $quote['refund'];
    }
    expect($returned)->toBe(50)
        ->and($cancelled)->toBe(898)
        ->and($refunded)->toBe(Money::kzt(29950)->amount)
        ->and((int) $w->bonuses($w->customer)->available)->toBe(50)   // back to the state before the sale
        ->and($line->refresh()->returned_qty)->toBe(3)->and($line->is_active)->toBeFalse()
        ->and(Reconciliation::problems())->toBe([]);
});

it('reverses an accrual from its own (expired) source and never takes the customer\'s other bonuses', function () {
    $w = world(['bonus' => ['accessory_percent' => '10.00', 'activation_days' => 0, 'lifetime_days' => 1]]);
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($w->owner);

    $this->travel(2)->days();                              // the 900 bonuses of the sale expired (no expire job yet)
    OpeningBalanceDraft::make($w->store)->bonus($w->customer, 300, now()->subHour(), now()->addYear())->post($w->owner);
    expect((int) $w->bonuses($w->customer)->available)->toBe(300);

    rev($sale, $w);
    expect((int) $w->bonuses($w->customer)->available)->toBe(300)
        ->and(Reconciliation::problems())->toBe([]);
});

it('restores the unit cost of a reversed intake: a reversed purchase leaves no cost, a reversed trade-in of our own phone restores the sale cost', function () {
    $w = world();
    $imei = World::imei();
    $purchase = PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $imei, Money::kzt(450000))->onCredit(now()->addDays(5))->post($w->owner);
    $unit = StockUnit::findByIdentifier($imei);
    rev($purchase, $w);
    expect($unit->refresh()->status)->toBeNull()->and($unit->cost_kzt)->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);

    // a module handler (trade-in of a phone we sold: sold → preparing with the buyout as the new cost)
    app(HandlerRegistry::class)->register(DocumentType::TradeIn, new class(app(InventoryLedger::class)) extends AbstractDocumentHandler
    {
        public function __construct(private readonly InventoryLedger $inventory) {}

        public function type(): DocumentType
        {
            return DocumentType::TradeIn;
        }

        public function validate(Document $document, PostingContext $ctx): void {}

        public function lockPlan(Document $document, PostingContext $ctx): LockPlan
        {
            return (new LockPlan)->unit((int) $document->meta['unit_id']);
        }

        public function post(Document $document, PostingContext $ctx): void
        {
            $unit = $ctx->locks->unit((int) $document->meta['unit_id']);
            $this->inventory->unitIn($ctx, $unit, Location::store($ctx->requireStoreId()), MovementKind::TradeInIn,
                UnitAction::TradeInKnown, UnitStatus::Preparing, (int) $document->meta['cost']);
        }
    });

    $phone = $w->phone(cost: Money::kzt(489250));
    $sale = SaleDraft::make($w->store, $w->owner)->unit($phone, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $act = new Document;
    $act->forceFill([
        'type' => DocumentType::TradeIn->value, 'status' => 'draft', 'store_id' => $w->store->id, 'occurred_at' => now(),
        'idempotency_key' => Idempotency::newKey(), 'meta' => ['unit_id' => $phone->id, 'cost' => Money::kzt(150000)->amount],
    ])->save();
    app(Poster::class)->post($act, $w->owner);
    expect($phone->refresh()->cost_kzt)->toBe(Money::kzt(150000)->amount);

    rev($act, $w);
    expect($phone->refresh()->status)->toBe(UnitStatus::Sold)
        ->and($phone->cost_kzt)->toBe(Money::kzt(489250)->amount);

    // the original buyer returns it: the phone comes back at the cost of that sale
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    $return = CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kzt(549000))->post($w->owner);
    expect((int) CustomerReturnLine::query()->where('document_id', $return->id)->value('cost_kzt'))->toBe((int) $line->cost_kzt)
        ->and(Reconciliation::problems())->toBe([]);
});

it('re-checks a return under locks: the sale document is share-locked and over-returns are refused', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->accessory($w->case, 2, Money::kzt(9000))->cash(Money::kzt(18000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    // two drafts prepared at the same time (both valid when created)
    $first = CustomerReturnDraft::make($sale)->line($line, 2)->refund('cash', Money::kzt(18000))->save();
    $second = CustomerReturnDraft::make($sale)->line($line, 2)->refund('cash', Money::kzt(18000))->save();

    DB::enableQueryLog();
    app(Poster::class)->post($first, $w->owner);
    $shareLocks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '"documents"') && str_contains($q, 'for share'));
    expect($shareLocks)->not->toBeEmpty();

    expect(fn () => app(Poster::class)->post($second, $w->owner))->toThrow(PostingException::class)
        ->and($w->accessoryQty($w->case))->toBe(5)
        ->and(Reconciliation::problems())->toBe([]);
});

it('refuses a return for another customer than the one of the sale', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    $other = Customer::query()->create(['name' => 'Чужой', 'phone' => '+77019998877']);
    $draft = CustomerReturnDraft::make($sale)->line($line, 1)->refundToDebt(Money::kzt(9000))->save();
    CustomerReturn::query()->where('document_id', $draft->id)->update(['customer_id' => $other->id]);

    expect(fn () => app(Poster::class)->post($draft, $w->owner))->toThrow(PostingException::class, 'клиент');
});

it('returns the existing document when the same idempotency key is inserted concurrently (unique violation)', function () {
    $w = world();
    $key = Idempotency::newKey();
    $racer = null;
    // simulate the other request: it inserts (and commits) the same key right after our lookup found nothing
    DB::listen(function ($query) use ($key, &$racer, $w) {
        if ($racer === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'idempotency_key') && in_array($key, $query->bindings, true)) {
            $racer = 0;
            $racer = DB::table('documents')->insertGetId([
                'organization_id' => $w->org->id, 'type' => 'opening_balance', 'status' => 'draft', 'store_id' => $w->store->id,
                'occurred_at' => now(), 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $doc = OpeningBalanceDraft::make($w->store)->money($w->account('cash_kzt'), Money::kzt(1000))->idempotencyKey($key)->save();
    expect($doc->id)->toBe($racer)
        ->and(Document::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('takes the organization row (FOR KEY SHARE) before posting and reversing so that a period close waits (TZ 02 §12.1)', function () {
    $w = world();
    $draft = OpeningBalanceDraft::make($w->store)->money($w->account('cash_kzt'), Money::kzt(1000))->save();
    DB::enableQueryLog();
    $posted = app(Poster::class)->post($draft, $w->owner);
    rev($posted, $w);
    $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '"organizations"') && str_contains($q, 'for key share'));
    expect($locks->count())->toBeGreaterThanOrEqual(2);
});

it('guards the document links in the database: reversed ⇔ reversed_by, detail rows cannot be moved into a posted document', function () {
    $w = world();
    $posted = $w->cash(Money::kzt(1000));
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $posted->id)->update(['status' => 'reversed'])))
        ->toThrow(QueryException::class);

    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->accessory($w->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($w->owner);
    $draft = SaleDraft::make($w->store, $w->owner)->accessory($w->case, 1, Money::kzt(1))->cash(Money::kzt(1))->save();
    expect(fn () => DB::transaction(fn () => DB::table('sale_lines')->where('document_id', $draft->id)->update(['document_id' => $sale->id])))
        ->toThrow(QueryException::class, 'Строки проведённого документа');
});

it('reverses a USD supplier payment with the exact mirrored valuation and keeps the average rate', function () {
    $w = world();
    $w->rate('515');
    OpeningBalanceDraft::make($w->store)->supplierDebt($w->supplier, Money::usd(10000), now()->addDays(20), '515')
        ->money($w->account('cash_kzt'), Money::kzt(5000000))->post($w->owner);
    $w->rate('520');
    $pay = DebtPaymentDraft::toSupplier($w->supplier, $w->account('cash_kzt'), Money::kzt(2080000), Currency::USD, Money::usd(4000))->post($w->owner);
    $debt = $w->debt('supplier', $w->supplier->id, 'USD');
    expect((int) $debt->balance)->toBe(Money::usd(-6000)->amount)->and((string) $debt->avg_rate)->toBe('515.0000');
    PurchaseDraft::make($w->store, $w->supplier, Currency::USD)->accessory($w->case, 100, Money::usd(50))->onCredit(now()->addDays(9))->post($w->owner);

    rev($pay, $w);
    $debt->refresh();
    // −$10 000 at 515 and −$5 000 at 520
    expect((int) $debt->balance)->toBe(Money::usd(-15000)->amount)
        ->and((int) $debt->balance_kzt)->toBe(Money::kzt(-5150000 - 2600000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});

it('never refunds more than was paid for a line and refunds only to tenge accounts', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $w->cash(Money::kzt(100000));
    $sale = SaleDraft::make($w->store, $w->owner)->accessory($w->case, 3, Money::kzt(10000))->cash(Money::kzt(30000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    // a manual amount above the line amount
    expect(fn () => CustomerReturnDraft::make($sale)->line($line, 1, amount: Money::kzt(40000))->refund('cash', Money::kzt(40000))->post($w->owner))
        ->toThrow(PostingException::class, 'не больше '.Money::kzt(30000)->format());
    // a smaller manual amount first; the proportional share of the next piece may not overshoot what is left
    CustomerReturnDraft::make($sale)->line($line, 1, amount: Money::kzt(25000))->refund('cash', Money::kzt(25000))->post($w->owner);
    expect(fn () => CustomerReturnDraft::make($sale)->line($line->refresh(), 1)->refund('cash', Money::kzt(10000))->post($w->owner))
        ->toThrow(PostingException::class, 'не больше '.Money::kzt(5000)->format());
    // refund to a $ account: the tenge amount would be written as cents
    expect(fn () => CustomerReturnDraft::make($sale)->line($line, 1, amount: Money::kzt(1000))->refund('cash', Money::kzt(1000), $w->account('cash_usd')->id)->post($w->owner))
        ->toThrow(PostingException::class, 'только в тенге');
    expect(Reconciliation::problems())->toBe([]);
});

it('refunds the unpaid credit part of a returned line by reducing the debt, not in cash (TZ 02 §6.2 №20, Л)', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $w->cash(Money::kzt(100000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 2, Money::kzt(10000))
        ->cash(Money::kzt(5000))->debt(Money::kzt(15000), now()->addDays(10))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    expect($line->debt_share)->toBe(Money::kzt(15000)->amount);

    // the whole check back in cash while 15 000 ₸ are still owed: refused
    $draft = CustomerReturnDraft::make($sale)->line($line, 2)->save();
    expect(app(ReturnCalculator::class)->quote($draft)['to_debt_min'])->toBe(Money::kzt(15000)->amount);
    expect(fn () => CustomerReturnDraft::make($sale)->line($line, 2)->refund('cash', Money::kzt(20000))->post($w->owner))
        ->toThrow(PostingException::class, 'в долг');

    // the customer paid 10 000 of the debt meanwhile: only the 5 000 still owed must go to the debt
    DebtPaymentDraft::fromCustomer($w->customer, $w->account('cash_kzt'), Money::kzt(10000), $w->store)->post($w->owner);
    CustomerReturnDraft::make($sale)->line($line, 2)->refundToDebt(Money::kzt(5000))->refund('cash', Money::kzt(15000))->post($w->owner);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});

it('changes a unit status without a document only for the journal-free transitions of TZ 02 §10.3', function () {
    $w = world();
    $unit = $w->phone();
    $service = app(UnitStatusService::class);

    // a «sale» by a bare status change would leave a sold phone without a sale line, movement or money
    expect(fn () => $service->change($unit, UnitStatus::Sold, UnitAction::Sell, $w->owner, 'sale.create'))->toThrow(LogicException::class)
        ->and(fn () => $service->change($unit, UnitStatus::Reserved, UnitAction::Reserve, $w->owner, 'sale.reserve'))->toThrow(LogicException::class);
    $service->sendToPreparation($unit, $w->owner);
    $service->markReady($unit->refresh(), $w->owner);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
        ->and(Reconciliation::problems())->toBe([]);
});
