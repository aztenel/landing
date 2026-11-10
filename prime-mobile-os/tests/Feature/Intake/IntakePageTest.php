<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\Document;
use App\Filament\Intake\Pages\Intakes;
use App\Filament\Intake\Pages\NewIntake;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;
use Tests\Support\World;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function imeiN(int $n): string
{
    return Imei::withCheckDigit('35693812'.str_pad((string) $n, 6, '0', STR_PAD_LEFT));
}

it('receives phones in $ by scanning one after another: Luhn, duplicate, already in base; debt to the supplier (scenarios 1–3)', function () {
    $w = world();
    $w->rate('515');
    $existing = $w->phone();
    $this->actingAs($w->admin);

    $page = Livewire::test(NewIntake::class)
        ->set('head.supplier_id', $w->supplier->id)->set('head.currency', 'USD')->set('head.pay', 'credit')->set('head.due', now()->addDays(14)->toDateString())
        ->call('pickVariant', 0, $w->iphone->id)->set('batches.0.price', '1140')->set('batches.0.sale_price', '649000')->set('batches.0.expected', '3')
        ->call('scan', imeiN(1))->call('scan', imeiN(2))
        ->call('scan', imeiN(1))                                 // duplicate
        ->call('scan', '356938124471200')                        // bad check digit (unless it happens to be right)
        ->call('scan', $existing->imei1);                        // already in the base
    $rows = $page->get('batches')[0]['rows'];
    expect($rows[2]['error'])->toContain('Уже отсканирован')
        ->and($rows[4]['error'])->toContain('уже есть');

    $page->call('askConfirm')->assertSet('error', fn ($e) => str_contains((string) $e, 'ошибками'));
    foreach ([4, 3, 2] as $r) {
        if ($page->get('batches')[0]['rows'][$r]['error'] ?? null) {
            $page->call('removeRow', 0, $r);
        }
    }
    $page->call('askConfirm')->assertSet('error', null)->assertSet('confirm', true)->call('post')->assertSet('error', null);

    $doc = Document::query()->where('type', 'purchase')->where('status', 'posted')->firstOrFail();
    $unit = StockUnit::findByIdentifier(imeiN(1));
    expect($unit->status)->toBe(UnitStatus::InStock)
        ->and($unit->cost_kzt)->toBe(Money::kzt(587100)->amount) // $1 140 × 515
        ->and($unit->sale_price)->toBe(Money::kzt(649000)->amount)
        ->and((int) $w->debt('supplier', $w->supplier->id, 'USD')->balance)->toBe(Money::usd(-2280)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    // the same click again (idempotency key of the page) does not post twice
    $page->set('posted', null)->call('post');
    expect(Document::query()->where('type', 'purchase')->where('status', 'posted')->count())->toBe(1);
});

it('lets a seller receive phones without the purchase price: cost unknown, not for sale; no price in the page (scenario 7)', function () {
    $w = world();
    $this->actingAs($w->seller);
    $page = Livewire::test(NewIntake::class)->assertDontSee('Закуп,')->assertSee('Себестоимость внесёт администратор')
        ->set('head.supplier_id', $w->supplier->id)->call('pickVariant', 0, $w->iphone->id)->set('batches.0.price', '999')
        ->call('scan', imeiN(7))->call('askConfirm')->call('post')->assertSet('error', null);

    $unit = StockUnit::findByIdentifier(imeiN(7));
    expect($unit->cost_unknown)->toBeTrue()->and((int) $unit->cost_kzt)->toBe(0); // the forged price was ignored
});

it('lists intakes and reverses one with a reason (Э11.1)', function () {
    $w = world();
    $this->actingAs($w->owner);
    Livewire::test(NewIntake::class)->set('head.supplier_id', $w->supplier->id)->set('head.pay', 'credit')
        ->call('pickVariant', 0, $w->iphone->id)->set('batches.0.price', '400000')->call('scan', imeiN(9))->call('askConfirm')->call('post');
    $doc = Document::query()->where('type', 'purchase')->where('status', 'posted')->firstOrFail();

    Livewire::test(Intakes::class)->assertSee($doc->number)->set('reverseId', $doc->id)->set('reason', 'ошибка в накладной')->call('reverse')->assertSet('error', null);
    expect($doc->refresh()->status->value)->toBe('reversed')
        ->and(StockUnit::findByIdentifier(imeiN(9))?->status)->toBeNull()
        ->and(Reconciliation::problems())->toBe([]);
});

it('imports an Excel file with a preview and refuses to add it while there are errors (Э11.4)', function () {
    $w = world();
    $this->actingAs($w->owner);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new OpenSpout\Writer\XLSX\Writer;
    $writer->openToFile($path);
    $writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Модель', 'Память', 'Цвет', 'IMEI 1', 'IMEI 2', 'Состояние', 'Цена', 'Розница']));
    $writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['iPhone 15 Pro', '256 ГБ', 'Black Titanium', imeiN(11), '', 'новый', 400000, 549000]));
    $writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Nokia 3310', '', '', imeiN(12), '', 'новый', 1000, 2000]));
    $writer->close();

    $page = Livewire::test(NewIntake::class)->set('file', \Illuminate\Http\UploadedFile::fake()->createWithContent('p.xlsx', file_get_contents($path)))->call('importFile');
    $preview = $page->get('preview');
    expect($preview)->toHaveCount(2)->and($preview[0]['error'])->toBeNull()->and($preview[1]['error'])->toContain('Неизвестная модель');

    $page->call('usePreview')->assertSet('error', fn ($e) => str_contains((string) $e, 'Ошибок: 1'))
        ->call('dropPreviewRow', 1)->call('usePreview')->assertSet('error', null);
    expect($page->get('batches')[0]['rows'][0]['imei1'])->toBe(imeiN(11));
});
