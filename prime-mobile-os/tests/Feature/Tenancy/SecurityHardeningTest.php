<?php

/*
 | Security review of the core (TZ 03 §4, §12, §17, §18): organization isolation of every reference in a document,
 | spatie teams, field visibility, IIN at rest, mass assignment, middleware of Livewire requests.
 | Each test pins a hole found in the review: it failed before the fix.
 */

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchasePayment;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\ImeiCheckService;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\InsufficientFunds;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\DebtEntry;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Models\OpeningBalanceLine;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\HandlerRegistry;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\User;
use App\Support\Crypto\BlindIndex;
use App\Support\Idempotency\Idempotency;
use App\Support\Iin\Iin;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\CrossOrganizationViolation;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Support\World;

/** Two organizations; the context is A, B is «the other firm» whose ids an attacker substitutes. */
function twoFirms(): array
{
    $a = world();
    $b = world(name: 'Чужая фирма');
    CurrentOrganization::instance()->set($a->org);

    return [$a, $b];
}

/** Runs a statement that must fail in the DB inside a savepoint (keeps the test transaction usable). */
function failsInDb(callable $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

// ---------------------------------------------------------------- ids of another organization in a document (TZ 03 §18 p.4)

it('rejects a purchase from a supplier of another organization', function () {
    [$a, $b] = twoFirms();

    expect(fn () => PurchaseDraft::make($a->store, $b->supplier->id)
        ->unit($a->iphone, World::imei(), Money::kzt(400000))->onCredit(now()->addDays(10))->post($a->owner))
        ->toThrow(PostingException::class, 'Поставщик не найден');

    expect(StockUnit::query()->count())->toBe(0)
        ->and(DebtEntry::withoutOrganizationScope()->where('counterparty_id', $b->supplier->id)->count())->toBe(0);
});

it('rejects debt payments of a customer or to a supplier of another organization', function () {
    [$a, $b] = twoFirms();
    $a->cash(Money::kzt(100000));

    expect(fn () => DebtPaymentDraft::fromCustomer($b->customer->id, $a->account('cash_kzt'), Money::kzt(10000), $a->store)->post($a->owner))
        ->toThrow(PostingException::class, 'Клиент не найден');
    expect(fn () => DebtPaymentDraft::toSupplier($b->supplier->id, $a->account('cash_kzt'), Money::kzt(10000), store: $a->store)->post($a->owner))
        ->toThrow(PostingException::class, 'Поставщик не найден');

    expect($a->balance('cash_kzt'))->toBe(Money::kzt(100000)->amount)
        ->and(DebtEntry::withoutOrganizationScope()->count())->toBe(0);
});

it('rejects an expense on credit to a supplier of another organization', function () {
    [$a, $b] = twoFirms();

    expect(fn () => ExpenseDraft::make(ArticleCode::Courier, $a->store)->onCredit($b->supplier->id, Money::kzt(5000), now()->addDays(5))->post($a->owner))
        ->toThrow(PostingException::class, 'Поставщик не найден');
});

it('rejects opening balances that point to customers or suppliers of another organization', function () {
    [$a, $b] = twoFirms();

    expect(fn () => OpeningBalanceDraft::make($a->store)->customerDebt($b->customer->id, Money::kzt(50000), now()->addDays(10))->post($a->owner))
        ->toThrow(PostingException::class, 'Клиент не найден')
        ->and(fn () => OpeningBalanceDraft::make($a->store)->bonus($b->customer->id, 5000)->post($a->owner))
        ->toThrow(PostingException::class, 'Клиент не найден')
        ->and(fn () => OpeningBalanceDraft::make($a->store)->unit($a->iphone, World::imei(), Money::kzt(400000), ['supplier_id' => $b->supplier->id])->post($a->owner))
        ->toThrow(PostingException::class, 'Поставщик не найден');

    // a bonus balance row of B's customer created by A would break B's own postings (unique customer_id)
    expect(CustomerBonusBalance::withoutOrganizationScope()->where('customer_id', $b->customer->id)->count())->toBe(0);
});

it('refunds a return only to the customer of the sale', function () {
    [$a, $b] = twoFirms();
    $a->accessories($a->case, 5, Money::kzt(2400));
    $sale = SaleDraft::make($a->store, $a->owner)->customer($a->customer)
        ->accessory($a->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($a->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    $draft = CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kzt(9000))->save();
    CustomerReturn::query()->where('document_id', $draft->id)->update(['customer_id' => $b->customer->id]);

    expect(fn () => app(Poster::class)->post($draft, $a->owner))
        ->toThrow(PostingException::class, 'не совпадает с клиентом продажи');
    expect(CustomerBonusBalance::withoutOrganizationScope()->where('customer_id', $b->customer->id)->count())->toBe(0);
});

it('does not let a sale be recorded on a seller who does not work in the organization', function () {
    [$a, $b] = twoFirms();
    $a->accessories($a->case, 5, Money::kzt(2400));

    expect(fn () => SaleDraft::make($a->store, $b->seller)->accessory($a->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($a->owner))
        ->toThrow(PostingException::class, 'Продавец не работает в этой фирме');
});

it('never reads bonus sources of a customer of another organization', function () {
    [$a, $b] = twoFirms();
    CurrentOrganization::instance()->run($b->org, fn () => OpeningBalanceDraft::make($b->store)->bonus($b->customer, 5000)->post($b->owner));

    expect(app(BonusLedger::class)->sources($b->customer->id))->toBeEmpty();
    CurrentOrganization::instance()->set($b->org);
    expect(app(BonusLedger::class)->sources($b->customer->id))->toHaveCount(1);
});

it('does not serve a cached money account of another organization', function () {
    [$a, $b] = twoFirms();
    $ledger = app(MoneyLedger::class);
    $accountOfA = $ledger->account($a->account('cash_kzt')->id);

    CurrentOrganization::instance()->set($b->org);
    expect(fn () => $ledger->account($accountOfA->id))->toThrow(ModelNotFoundException::class);
});

it('does not record a госбаза check on a phone of another organization', function () {
    [$a, $b] = twoFirms();
    $unitOfB = CurrentOrganization::instance()->run($b->org, fn () => $b->phone());

    expect(fn () => app(ImeiCheckService::class)->record($unitOfB, ImeiCheckResult::Registered, $a->owner))
        ->toThrow(CrossOrganizationViolation::class);
});

// ---------------------------------------------------------------- the database refuses foreign references (defense in depth, RLS is off)

it('refuses rows that reference another organization at the database level', function () {
    [$a, $b] = twoFirms();
    $now = now();
    $cashOfB = CurrentOrganization::instance()->run($b->org, fn () => $b->account('cash_kzt')->id);

    expect(failsInDb(fn () => DB::table('customer_bonus_balances')->insert(['organization_id' => $a->org->id, 'customer_id' => $b->customer->id, 'updated_at' => $now])))->toBeTrue()
        ->and(failsInDb(fn () => DB::table('counterparty_balances')->insert(['organization_id' => $a->org->id, 'counterparty_type' => 'customer', 'counterparty_id' => $b->customer->id, 'currency' => 'KZT', 'updated_at' => $now])))->toBeTrue()
        ->and(failsInDb(fn () => DB::table('counterparty_balances')->insert(['organization_id' => $a->org->id, 'counterparty_type' => 'supplier', 'counterparty_id' => $b->supplier->id, 'currency' => 'KZT', 'updated_at' => $now])))->toBeTrue()
        ->and(failsInDb(fn () => DB::table('account_balances')->insert(['organization_id' => $a->org->id, 'account_id' => $cashOfB, 'currency' => 'KZT', 'updated_at' => $now])))->toBeTrue()
        ->and(failsInDb(fn () => OpeningBalanceDraft::make($b->store->id)->money($a->account('cash_kzt'), Money::kzt(1000))->save()))->toBeTrue();

    // own references still work
    expect(failsInDb(fn () => DB::table('counterparty_balances')->insert(['organization_id' => $a->org->id, 'counterparty_type' => 'customer', 'counterparty_id' => $a->customer->id, 'currency' => 'KZT', 'updated_at' => $now])))->toBeFalse();
});

// ---------------------------------------------------------------- posting by people of the organization only

it('posts only for an active member of the organization even if a module handler forgets to authorize', function () {
    [$a, $b] = twoFirms();
    app(HandlerRegistry::class)->register(DocumentType::CashMove, new class extends AbstractDocumentHandler
    {
        public function type(): DocumentType
        {
            return DocumentType::CashMove;
        }

        public function validate(Document $document, PostingContext $ctx): void {}

        public function lockPlan(Document $document, PostingContext $ctx): LockPlan
        {
            return new LockPlan;
        }

        public function post(Document $document, PostingContext $ctx): void {}
    });
    $draft = fn () => tap(new Document, fn (Document $d) => $d->forceFill([
        'type' => DocumentType::CashMove->value, 'status' => 'draft', 'store_id' => $a->store->id, 'occurred_at' => now(),
        'idempotency_key' => Idempotency::newKey(), 'created_by' => $a->owner->id,
    ])->save());

    expect(fn () => app(Poster::class)->post($draft(), $b->owner))->toThrow(AccessDenied::class, 'Доступ закрыт');

    Membership::query()->where('user_id', $a->seller->id)->update(['status' => 'blocked']);
    expect(fn () => app(Poster::class)->post($draft(), $a->seller))->toThrow(AccessDenied::class, 'Доступ закрыт');

    expect(app(Poster::class)->post($draft(), $a->owner)->isPosted())->toBeTrue();
});

it('does not let a made-up approval request id take a check out of the daily discount limit (L3)', function () {
    $w = world();
    $units = collect(range(1, 5))->map(fn () => $w->phone());
    $sell = fn (StockUnit $unit, ?int $approval) => SaleDraft::make($w->store, $w->seller)
        ->unit($unit, Money::kzt(549000))->discount(Money::kzt(5000))->cash(Money::kzt(544000))
        ->approvalRequest($approval)->post($w->seller);

    // 4 × 5 000 ₸ = the seller's day limit 20 000 ₸; nobody approved anything
    $docs = $units->take(4)->map(fn ($u) => $sell($u, 999));
    expect($docs->every(fn (Document $d) => $d->refresh()->approval_request_id === null))->toBeTrue();

    expect(fn () => $sell($units[4], null))->toThrow(ApprovalRequired::class, 'Скидка за день');
});

it('keeps a seller of one store away from the cash account of another store', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2400));
    $otherStore = Store::query()->create(['name' => 'ТРЦ «Мега»']);
    $otherCash = MoneyAccount::query()->create(['code' => 'cash_kzt_mega', 'name' => 'Касса ₸ — Мега', 'type' => 'cash', 'currency' => 'KZT', 'store_id' => $otherStore->id]);

    expect(fn () => SaleDraft::make($w->store, $w->seller)->accessory($w->case, 1, Money::kzt(9000))
        ->pay('cash', Money::kzt(9000), ['account_id' => $otherCash->id])->post($w->seller))
        ->toThrow(AccessDenied::class, 'счёт другого магазина');

    // the owner works in all stores
    expect(SaleDraft::make($w->store, $w->owner)->accessory($w->case, 1, Money::kzt(9000))
        ->pay('cash', Money::kzt(9000), ['account_id' => $otherCash->id])->post($w->owner)->isPosted())->toBeTrue();
});

it('does not reveal a bank balance in an error to a role that does not see it (TZ 03 §4.1)', function () {
    $w = world();
    $senior = User::factory()->create(['name' => 'Старший']);
    app(OrganizationProvisioner::class)->addMember($w->org, $senior, RoleTemplates::SENIOR_SELLER);
    $w->accessories($w->case, 5, Money::kzt(2400));
    $sale = SaleDraft::make($w->store, $w->owner)->accessory($w->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    $refund = fn (User $by) => CustomerReturnDraft::make($sale)->line($line, 1)->refund('kaspi_transfer', Money::kzt(9000))->post($by);

    try {
        $refund($senior);
        $this->fail('InsufficientFunds expected');
    } catch (InsufficientFunds $e) {
        expect($e->getMessage())->toBe('На счёте «Kaspi Gold» не хватает денег')
            ->and($e->context)->not->toHaveKey('balance');
    }
    expect(fn () => $refund($w->owner))->toThrow(InsufficientFunds::class, 'не хватает 9');
});

it('starts every queued job of a worker without the previous job\'s organization', function () {
    $w = world();
    expect(CurrentOrganization::instance()->id())->toBe($w->org->id);

    event(new JobProcessing('sync', new SyncJob(app(), '{}', 'sync', 'default')));
    expect(CurrentOrganization::instance()->id())->toBe($w->org->id); // sync job = inside the request

    event(new JobProcessing('redis', new SyncJob(app(), '{}', 'redis', 'default')));
    expect(CurrentOrganization::instance()->has())->toBeFalse();
});

// ---------------------------------------------------------------- spatie teams

it('ignores a role of another organization attached to a user (spatie teams)', function () {
    [$a, $b] = twoFirms();
    $ownerRoleOfB = Role::query()->where('organization_id', $b->org->id)->where('name', 'owner')->firstOrFail();
    $a->seller->assignRole($ownerRoleOfB);
    $a->seller->unsetRelation('roles')->unsetRelation('permissions');

    expect($a->seller->can('stock.view_cost'))->toBeFalse()
        ->and(app(AccessChecker::class)->isOwner($a->seller))->toBeFalse()
        ->and(app(AccessChecker::class)->can($a->seller, 'stock.view_cost'))->toBeFalse();
});

// ---------------------------------------------------------------- HTTP / Livewire context

it('applies the organization middleware to Livewire update requests too', function () {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(SetCurrentOrganization::class);
});

it('closes a deactivated organization even with a live session', function () {
    $w = world();
    CurrentOrganization::instance()->clear();
    Route::middleware(['web', 'auth', 'organization'])->get('/_test/org-active', fn () => response()->json(['org' => CurrentOrganization::instance()->id()]));
    Organization::query()->whereKey($w->org->id)->update(['is_active' => false]);

    $this->actingAs($w->seller)->withSession([SetCurrentOrganization::SESSION_KEY => $w->org->id])
        ->get('/_test/org-active')->assertForbidden();
    expect($w->seller->hasActiveMembershipIn($w->org->id))->toBeFalse()
        ->and($w->seller->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('writes the organization into the activity log (TZ 03 §12 p.1)', function () {
    $w = world();
    $w->cash(Money::kzt(1000));

    $activity = Activity::query()->where('log_name', 'documents')->latest('id')->firstOrFail();
    expect((int) $activity->organization_id)->toBe($w->org->id);
});

// ---------------------------------------------------------------- field visibility (TZ 03 §4)

it('hides purchase cost, supplier and return cost from a seller in every detail model', function () {
    $w = world();
    $w->phone();

    expect(Purchase::hiddenFieldsFor($w->seller))->toContain('total', 'credit_amount', 'supplier_id')
        ->and(PurchasePayment::hiddenFieldsFor($w->seller))->toContain('amount', 'covered_amount')
        ->and(CustomerReturnLine::hiddenFieldsFor($w->seller))->toContain('cost_kzt')
        ->and(Purchase::hiddenFieldsFor($w->owner))->toBe([]);

    $line = OpeningBalanceLine::query()->where('line_type', 'unit')->firstOrFail();
    expect($line->forViewer($w->seller)->toArray())->not->toHaveKeys(['unit_cost_kzt', 'cost_amount_orig', 'supplier_id'])
        ->and(OpeningBalanceLine::query()->find($line->id)->forViewer($w->owner)->toArray())->toHaveKeys(['unit_cost_kzt', 'supplier_id']);
});

// ---------------------------------------------------------------- IIN (TZ 03 §17)

it('stores the IIN encrypted with a blind index and never serializes it', function () {
    $w = world();
    $iin = collect(range(0, 9))->map(fn ($d) => '90010130001'.$d)->first(fn ($v) => Iin::isValid($v));
    $customer = Customer::query()->create(['name' => 'Продавец б/у', 'phone' => '+77015550011', 'iin' => $iin]);

    $raw = DB::table('customers')->where('id', $customer->id)->first();
    expect($raw->iin)->not->toContain($iin)
        ->and($raw->iin_hash)->toBe(BlindIndex::make($iin))
        ->and($raw->iin_last4)->toBe(substr($iin, -4))
        ->and($customer->toArray())->not->toHaveKeys(['iin', 'iin_hash'])
        ->and(json_encode($customer))->not->toContain($iin)
        ->and(Customer::query()->whereIin($iin)->value('id'))->toBe($customer->id)
        ->and(Customer::query()->find($customer->id)->iin)->toBe($iin);
});

// ---------------------------------------------------------------- mass assignment

it('keeps ledger-maintained and privileged columns out of mass assignment', function () {
    $w = world();

    $unit = (new StockUnit)->fill(['grade' => 'A', 'sale_price' => 100, 'imei1' => '356938124471206', 'supplier_id' => 1, 'variant_id' => 1, 'status' => 'sold', 'cost_kzt' => 1]);
    expect(array_keys($unit->getAttributes()))->toBe(['grade']);

    expect(fn () => (new MoneyEntry)->fill(['amount' => 100]))->toThrow(MassAssignmentException::class)
        ->and(fn () => (new AccountBalance)->fill(['balance' => 100]))->toThrow(MassAssignmentException::class)
        ->and(fn () => (new CustomerBonusBalance)->fill(['available' => 100]))->toThrow(MassAssignmentException::class);

    expect((new Organization)->fill(['name' => 'X', 'closed_until' => null])->getAttributes())->not->toHaveKey('closed_until')
        ->and((new User)->fill(['name' => 'X', 'is_superadmin' => true])->getAttributes())->not->toHaveKey('is_superadmin')
        ->and((new Document)->fill(['status' => 'posted', 'number' => 'ПРД-1', 'posted_by' => $w->owner->id])->getAttributes())
        ->not->toHaveKeys(['number', 'posted_by'])
        ->and((new Document)->fill(['status' => 'posted'])->status->value)->toBe('draft');
});
