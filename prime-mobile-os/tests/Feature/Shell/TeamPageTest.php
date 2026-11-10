<?php

use App\Domain\Tenancy\Models\Membership;
use App\Filament\Shell\Pages\Team;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('opens «Сотрудники и роли» for the owner, not for a seller (Э25)', function () {
    $w = world();
    $url = 'http://'.config('prime.erp_domain').'/team';
    $this->actingAs($w->owner)->get($url)->assertOk()->assertSee('Пригласить сотрудника');
    $this->actingAs($w->seller)->get($url)->assertForbidden();
});

it('invites an employee with a link and a store, blocks him (Э25, audit №10)', function () {
    $w = world();
    $this->actingAs($w->owner);
    $sellerRole = \Spatie\Permission\Models\Role::query()->where('organization_id', $w->org->id)->where('name', 'seller')->first();

    Livewire::test(Team::class)
        ->set('inviteName', 'Дана Новая')->set('invitePhone', '+77015556677')->set('inviteRole', $sellerRole->id)
        ->call('invite')
        ->assertSet('inviteLink', fn ($v) => is_string($v) && str_contains($v, '/invite/'));

    $m = Membership::query()->whereHas('user', fn ($q) => $q->where('phone', '+77015556677'))->firstOrFail();
    expect($m->stores()->count())->toBe(1);

    Livewire::test(Team::class)->call('staffAction', $m->id, 'block');
    expect($m->refresh()->status->value)->toBe('blocked');
});

it('saves a role limit in tenge and refuses a seller who forges the call', function () {
    $w = world();
    $sellerRole = \Spatie\Permission\Models\Role::query()->where('organization_id', $w->org->id)->where('name', 'seller')->first();

    $this->actingAs($w->owner);
    Livewire::test(Team::class)->call('loadRole', $sellerRole->id)->set('limits.L1', '7000')->call('saveRole');
    expect((int) \App\Domain\Tenancy\Models\RoleLimit::query()->where('role_id', $sellerRole->id)->where('code', 'L1')->value('value'))->toBe(700000);

    $this->actingAs($w->seller);
    Livewire::test(Team::class)->assertForbidden();
});

it('shows the rights the role has and keeps them when saving (dots in permission codes)', function () {
    $w = world();
    $admin = \Spatie\Permission\Models\Role::query()->where('organization_id', $w->org->id)->where('name', 'admin')->first();
    $this->actingAs($w->owner);
    $page = Livewire::test(Team::class)->call('loadRole', $admin->id)->assertSet('state.sale__create.on', true);
    $page->set('state.sale__price_up.on', false)->call('saveRole');
    expect($admin->fresh()->hasPermissionTo('sale.create'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('sale.price_up'))->toBeFalse();
});
