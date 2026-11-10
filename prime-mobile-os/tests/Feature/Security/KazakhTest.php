<?php

use Filament\Facades\Filament;

it('speaks Kazakh in the ERP when the person chose it in the profile', function () {
    $w = world();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $w->seller->forceFill(['locale' => 'kk'])->save();
    $this->actingAs($w->seller)->get('http://erp.localhost/profile')->assertOk()->assertSee('Менің профилім')->assertSee('Интерфейс тілі');
    $w->seller->forceFill(['locale' => 'ru'])->save();
    $this->actingAs($w->seller->refresh())->get('http://erp.localhost/profile')->assertOk()->assertSee('Мой профиль');
});

it('answers /health with the state of each part, without secrets', function () {
    $r = $this->get('http://erp.localhost/health')->assertOk()->json();
    expect($r['ok'])->toBeTrue()->and(array_keys($r['checks']))->toBe(['database', 'redis', 'storage', 'outbox', 'backup']);
});
