<?php

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\CrossOrganizationViolation;
use App\Support\Tenancy\Exceptions\NoOrganizationSelected;
use Illuminate\Support\Facades\Route;

it('does not show organization A data to organization B (TZ 03 §12)', function () {
    $a = world(name: 'Prime Mobile');
    $a->phone();
    $a->cash(Money::kzt(100000));
    expect(Customer::query()->count())->toBe(1)
        ->and(StockUnit::query()->count())->toBe(1);

    $b = world(name: 'Второй филиал');
    expect(CurrentOrganization::instance()->id())->toBe($b->org->id)
        ->and(Customer::query()->pluck('name')->all())->toBe(['Ерлан'])   // only B's own customer
        ->and(Customer::query()->whereKey($a->customer->id)->first())->toBeNull()
        ->and(StockUnit::query()->count())->toBe(0)
        ->and(Document::query()->where('organization_id', $a->org->id)->count())->toBe(0)
        ->and(MoneyEntry::query()->count())->toBe(0)
        ->and(Customer::withoutOrganizationScope()->count())->toBe(2);
});

it('lets the same IMEI exist in another organization but not twice in one', function () {
    $a = world();
    $unit = $a->phone();
    $b = world(name: 'Второй филиал');

    $sameImei = OpeningBalanceDraft::make($b->store)
        ->unit($b->iphone, $unit->imei1, Money::kzt(400000))->post($b->owner);
    expect($sameImei->isPosted())->toBeTrue();

    expect(fn () => OpeningBalanceDraft::make($b->store)
        ->unit($b->iphone, $unit->imei1, Money::kzt(400000))->post($b->owner))
        ->toThrow(PostingException::class, 'уже есть в базе');
});

it('fails loudly when tenant data is read or written without an organization', function () {
    world();
    CurrentOrganization::instance()->clear();

    expect(fn () => Customer::query()->count())->toThrow(NoOrganizationSelected::class)
        ->and(fn () => Customer::query()->create(['name' => 'X', 'phone' => '+77010000099']))->toThrow(NoOrganizationSelected::class);
});

it('refuses to write a row of another organization', function () {
    $a = world();
    $b = world(name: 'Второй филиал');

    expect(fn () => Customer::query()->create(['organization_id' => $a->org->id, 'name' => 'X', 'phone' => '+77010000098']))
        ->toThrow(CrossOrganizationViolation::class);
});

it('runs code in another organization context and restores the previous one', function () {
    $a = world();
    $b = world(name: 'Второй филиал');

    $count = CurrentOrganization::instance()->run($a->org, fn () => Customer::query()->where('name', 'Ерлан')->count());
    expect($count)->toBe(1)->and(CurrentOrganization::instance()->id())->toBe($b->org->id);
});

it('selects the organization of the request in the middleware', function () {
    $a = world();
    CurrentOrganization::instance()->clear();
    Route::middleware(['web', 'auth', 'organization'])->get('/_test/org', fn () => response()->json([
        'org' => CurrentOrganization::instance()->id(),
        'can' => request()->user()->can('stock.view_cost'),
    ]));

    // single organization → selected automatically, permissions of that team
    $this->actingAs($a->seller)->get('/_test/org')->assertOk()->assertJson(['org' => $a->org->id, 'can' => false]);
    CurrentOrganization::instance()->clear();
    $this->actingAs($a->owner)->get('/_test/org')->assertOk()->assertJson(['org' => $a->org->id, 'can' => true]);

    // a person working in two organizations must choose; a foreign organization in the session is dropped
    $b = world(name: 'Второй филиал');
    app(OrganizationProvisioner::class)->addMember($b->org, $a->seller, RoleTemplates::SELLER);
    CurrentOrganization::instance()->clear();
    $this->flushSession();
    $this->actingAs($a->seller)->get('/_test/org')->assertRedirect(route('organization.select'));
    $this->actingAs($a->seller)->withSession([SetCurrentOrganization::SESSION_KEY => $b->org->id])
        ->get('/_test/org')->assertOk()->assertJson(['org' => $b->org->id]);

    $this->flushSession();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->withSession([SetCurrentOrganization::SESSION_KEY => $a->org->id])->get('/_test/org')->assertForbidden();
});

it('keeps accessories per firm: another firm does not see them and may reuse the article and barcode (audit №9)', function () {
    $a = world();
    $caseOfA = $a->case;
    $b = world(name: 'Другая фирма');   // switches the current organization to B

    expect(\App\Domain\Catalog\Models\Accessory::query()->whereKey($caseOfA->id)->exists())->toBeFalse()
        ->and($b->case->id)->not->toBe($caseOfA->id)
        ->and($b->case->barcode)->toBe($caseOfA->barcode);

    // B cannot sell A's accessory: stock rows of B may not point to it
    expect(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => \Illuminate\Support\Facades\DB::table('accessory_costs')->insert([
        'organization_id' => $b->org->id, 'accessory_id' => $caseOfA->id, 'total_qty' => 0, 'avg_cost_kzt' => 0, 'updated_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('numbers public codes per firm with the firm prefix (audit №43)', function () {
    $a = world();
    $first = $a->phone();
    $b = world(['storefront' => ['code_prefix' => 'XX']], 'Другая фирма');
    $other = $b->phone();

    expect($first->refresh()->public_code)->toBe('PM-10001')
        ->and($other->refresh()->public_code)->toBe('XX-10001')
        ->and($b->phone()->refresh()->public_code)->toBe('XX-10002');
});
