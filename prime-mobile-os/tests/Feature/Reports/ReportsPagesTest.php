<?php

use App\Domain\Reports\Catalog\Registry;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Filament\Reports\Pages\Reports;
use App\Filament\Shell\Pages\Statistics;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function reportWorld()
{
    $w = world();
    $unit = $w->phone(cost: Money::kzt(489250));
    $w->accessories($w->case, 5, Money::kzt(2400));
    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->accessory($w->case, 1, Money::kzt(9000))
        ->cash(Money::kzt(558000))->post($w->seller);

    return $w;
}

it('opens every report of the catalog for the owner without errors', function () {
    $w = reportWorld();
    $this->actingAs($w->owner);
    $page = Livewire::test(Reports::class);
    foreach (Registry::all() as $r) {
        $page->set('r', $r->key())->assertOk()->assertSee($r->code().'.');
    }
    $page->set('r', 'sales')->assertSee("558\u{00A0}000")->assertSee('Отчёт о прибылях')->assertSee('Чистая прибыль');
});

it('never sends cost and profit to a role without the right (TZ 06 §1.3, Т12)', function () {
    $w = reportWorld();
    $this->actingAs($w->admin);                       // admin: cost yes, profit no (default role)
    $html = Livewire::test(Reports::class)->set('r', 'sales')->assertOk()->html();
    expect($html)->toContain("558\u{00A0}000")->not->toContain('Валовая прибыль')->not->toContain('Отчёт о прибылях')->not->toContain("63\u{00A0}350");

    $this->actingAs($w->seller);                      // seller: no reports at all
    $this->get(Reports::getUrl())->assertForbidden();
});

it('exports a report to Excel with numbers, without closed columns', function () {
    $w = reportWorld();
    $this->actingAs($w->owner);
    $r = $this->get(route('erp.reports.export', ['r' => 'sales', 'period' => 'today']));
    $r->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('.xlsx');
    $path = $r->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/sharedStrings.xml').$zip->getFromName('xl/worksheets/sheet1.xml');
    expect($xml)->toContain('Валовая прибыль')->toContain('558000');

    $this->actingAs($w->accountant);
    $this->get(route('erp.reports.export', ['r' => 'sales', 'period' => 'today']))->assertOk();
});

it('shows Statistics with real figures; a seller sees only his sales', function () {
    $w = reportWorld();
    $this->actingAs($w->owner);
    Livewire::test(Statistics::class)->assertOk()->assertSee("558\u{00A0}000")->assertSee('Прибыль')->assertSee('Нужно сделать')->assertSee('Айдар');

    $this->actingAs($w->seller2);
    Livewire::test(Statistics::class)->assertOk()->assertSee('Мои продажи')->assertDontSee("558\u{00A0}000")->assertDontSee('Прибыль');
    $this->actingAs($w->seller);
    Livewire::test(Statistics::class)->assertOk()->assertSee("558\u{00A0}000")->assertDontSee('маржа');
});
