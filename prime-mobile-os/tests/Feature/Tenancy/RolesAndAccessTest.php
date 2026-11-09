<?php

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Tenancy\Access\PermissionCatalog;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Models\Membership;
use App\Support\Money\Money;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('installs the five role templates of TZ 03 §2 with the full permission list', function () {
    $w = world();

    expect(Permission::query()->count())->toBe(count(PermissionCatalog::MATRIX))
        ->and(Role::query()->where('organization_id', $w->org->id)->pluck('name')->sort()->values()->all())
        ->toBe(['accountant', 'admin', 'owner', 'seller', 'senior_seller']);

    $owner = Role::query()->where('organization_id', $w->org->id)->where('name', RoleTemplates::OWNER)->first();
    expect($owner->permissions()->count())->toBe(count(PermissionCatalog::MATRIX))
        ->and($owner->is_owner)->toBeTrue();

    expect($w->owner->can('stock.view_cost'))->toBeTrue()
        ->and($w->admin->can('stock.view_cost'))->toBeTrue()
        ->and($w->accountant->can('stock.view_cost'))->toBeTrue()
        ->and($w->seller->can('stock.view_cost'))->toBeFalse()
        ->and($w->seller->can('sale.create'))->toBeTrue()
        ->and($w->seller->can('sale.discount'))->toBeTrue()
        ->and($w->seller->can('doc.opening_balance'))->toBeFalse()
        ->and($w->accountant->can('sale.create'))->toBeFalse()
        ->and($w->admin->can('report.profit'))->toBeFalse();
});

it('answers allowed / needs approval / denied with limits (TZ 03 §5, §19 p.2)', function () {
    $w = world();
    $access = app(AccessChecker::class);

    // seller: discount 5 000 ₸ per check
    expect($access->decide($w->seller, 'sale.discount', Limit::DiscountPerCheck, Money::kzt(4000)->amount)->isAllowed())->toBeTrue();
    $over = $access->decide($w->seller, 'sale.discount', Limit::DiscountPerCheck, Money::kzt(6000)->amount);
    expect($over->needsApproval())->toBeTrue()
        ->and($over->limitValue)->toBe(Money::kzt(5000)->amount)
        ->and($over->message)->toContain('5');
    // admin: 30 000 ₸; owner: unlimited
    expect($access->decide($w->admin, 'sale.discount', Limit::DiscountPerCheck, Money::kzt(6000)->amount)->isAllowed())->toBeTrue()
        ->and($access->decide($w->owner, 'sale.discount', Limit::DiscountPerCheck, Money::kzt(900000)->amount)->isAllowed())->toBeTrue();
    // «З»: seller may return a phone only with an approval; accountant may not sell at all
    expect($access->decide($w->seller, 'return.phone')->needsApproval())->toBeTrue()
        ->and($access->decide($w->accountant, 'sale.create')->isDenied())->toBeTrue();
});

it('lets personal limits override the role and blocks inactive members (TZ 03 §5.3)', function () {
    $w = world();
    $access = app(AccessChecker::class);
    Membership::query()->where('user_id', $w->seller->id)->update(['limits' => json_encode(['L1' => Money::kzt(10000)->amount])]);
    expect($access->decide($w->seller, 'sale.discount', Limit::DiscountPerCheck, Money::kzt(8000)->amount)->isAllowed())->toBeTrue();

    Membership::query()->where('user_id', $w->seller->id)->update(['status' => 'blocked']);
    expect($access->decide($w->seller, 'sale.create')->isDenied())->toBeTrue();
});

it('removes cost and supplier fields from data for a seller (TZ 03 §4.2, §19 p.1)', function () {
    $w = world();
    $unit = $w->phone();
    $mov = InventoryMovement::query()->get();

    $forSeller = StockUnit::query()->find($unit->id)->forViewer($w->seller)->toArray();
    expect($forSeller)->not->toHaveKeys(['cost_kzt', 'cost_currency', 'cost_amount_orig', 'cost_rate', 'supplier_id'])
        ->and($forSeller)->toHaveKeys(['public_code', 'sale_price', 'status'])
        ->and(json_encode(InventoryMovement::hideForViewer($mov, $w->seller)))->not->toContain('unit_cost_kzt');

    $forOwner = StockUnit::query()->find($unit->id)->forViewer($w->owner)->toArray();
    expect($forOwner)->toHaveKeys(['cost_kzt', 'supplier_id']);
    expect(StockUnit::hiddenFieldsFor($w->admin))->toBe([]);
});
