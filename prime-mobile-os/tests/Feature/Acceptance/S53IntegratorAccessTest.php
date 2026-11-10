<?php

use App\Domain\Ledger\Posting\Poster;
use App\Domain\Tenancy\Services\IntegratorAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;

// ТЗ 07 §6, сценарий 53: допуск интегратора — только по разрешению владельца, временно, без проведения документов.

it('53: the integrator sees nothing without an access, the owner allows 1 hour with his password, it ends by itself', function () {
    $w = world();
    $integrator = User::factory()->create(['name' => 'Интегратор', 'is_superadmin' => true]);
    expect($integrator->hasActiveMembershipIn($w->org->id))->toBeFalse();

    $svc = app(IntegratorAccess::class);
    expect(fn () => $svc->grant($integrator, 1, 'Проверить отчёт О3', $w->owner, 'wrong'))->toThrow(UserFacingException::class);
    expect(fn () => $svc->grant($integrator, 1, 'Проверить отчёт О3', $w->admin, 'password'))->toThrow(UserFacingException::class);

    $m = $svc->grant($integrator, 1, 'Проверить отчёт О3', $w->owner, 'password');
    expect($m->isActive())->toBeTrue()
        ->and($integrator->fresh()->hasActiveMembershipIn($w->org->id))->toBeTrue()
        ->and(IntegratorAccess::active()?->id)->toBe($m->id)
        ->and($integrator->fresh()->can('report.sales'))->toBeTrue()
        ->and($integrator->fresh()->can('sale.create'))->toBeFalse();

    // the strip «Идёт техподдержка до …» is seen by the staff
    $this->actingAs($w->seller)->get('http://erp.localhost/')->assertSee('Идёт техподдержка до');

    $this->travel(61)->minutes();
    expect($m->fresh()->isActive())->toBeFalse()
        ->and($integrator->fresh()->hasActiveMembershipIn($w->org->id))->toBeFalse()
        ->and(IntegratorAccess::active())->toBeNull();
});

it('53: under a support access the integrator cannot post a document; the owner closes it early; all is in the log', function () {
    $w = world();
    $integrator = User::factory()->create(['is_superadmin' => true]);
    $svc = app(IntegratorAccess::class);
    $m = $svc->grant($integrator, 4, 'Разобрать ошибку кассы', $w->owner, 'password');

    $draft = \App\Domain\Ledger\Drafts\OpeningBalanceDraft::make($w->store)->money($w->account('cash_kzt'), \App\Support\Money\Money::kzt(1000));
    expect(fn () => $draft->post($integrator))->toThrow(\App\Domain\Tenancy\Access\Exceptions\AccessDenied::class, 'Техподдержка не проводит документы');

    $svc->close($m, $w->owner);
    expect($m->fresh()->isActive())->toBeFalse();
    expect(\Spatie\Activitylog\Models\Activity::query()->where('log_name', 'support')->pluck('event')->all())
        ->toContain('support.granted')->toContain('support.closed');
});

it('53: the owner opens and closes the support access on the Team page', function () {
    $w = world();
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    User::factory()->create(['is_superadmin' => true]);
    $this->actingAs($w->owner);

    \Livewire\Livewire::test(\App\Filament\Shell\Pages\Team::class)->assertSee('Техподдержка')
        ->set('supportPurpose', 'Проверить остатки')->set('supportHours', 4)->set('supportPassword', 'password')
        ->call('grantSupport')->assertSee('Закрыть доступ');
    expect(IntegratorAccess::active()?->support_purpose)->toBe('Проверить остатки');

    \Livewire\Livewire::test(\App\Filament\Shell\Pages\Team::class)->call('closeSupport');
    expect(IntegratorAccess::active())->toBeNull();
});
