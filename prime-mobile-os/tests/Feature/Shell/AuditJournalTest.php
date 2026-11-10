<?php

use Filament\Facades\Filament;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('shows the journal of actions to the owner and hides it from a seller (Э27)', function () {
    $w = world();
    \App\Domain\Sales\Drafts\SaleDraft::make($w->store, $w->seller)->unit($w->phone(), \App\Support\Money\Money::kzt(900000))
        ->cash(\App\Support\Money\Money::kzt(900000))->post($w->owner);
    $url = 'http://'.config('prime.erp_domain').'/audit';

    $this->actingAs($w->owner)->get($url)->assertOk()->assertSee('провёл документ')->assertSee('ПРД-');
    $this->actingAs($w->seller)->get($url)->assertForbidden();
});
