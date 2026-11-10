<?php

use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SitePage;
use App\Domain\Storefront\Models\SiteStore;
use App\Domain\Storefront\Services\Leads;
use App\Domain\Storefront\Services\ListingBuilder;
use App\Domain\Storefront\Services\Photos;
use App\Domain\Storefront\Services\SiteSettings;
use App\Filament\Site\Pages\Site;
use App\Livewire\Stock\UnitPassport;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\ErpSearch;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('public');
    Storage::fake('local');
});

function sitePhoto(): UploadedFile
{
    $im = imagecreatetruecolor(1000, 1000);
    imagefill($im, 0, 0, imagecolorallocate($im, 220, 220, 225));
    ob_start();
    imagejpeg($im);

    return UploadedFile::fake()->createWithContent('photo.jpg', ob_get_clean());
}

it('passport tab «Сайт»: photo → on the site, hide, description, pin', function () {
    $w = world();
    $unit = $w->phone($w->usedIphone, Money::kzt(150000), ['condition' => 'used', 'grade' => 'A', 'battery_percent' => 91, 'sale_price' => Money::kzt(229000)]);
    $this->actingAs($w->owner);

    $c = Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->set('tabName', 'site')
        ->assertSee('Нет фото')
        ->set('uploads', [sitePhoto(), sitePhoto()])
        ->assertSee('Загружено фото: 2')
        ->assertSee('На сайте с');
    expect(Listing::query()->where('unit_ref', $unit->id)->value('public_status'))->toBe('available');

    $c->set('site.description_ru', 'Царапина на рамке')->set('site.hit', true)->call('saveSite')->assertSee('Сохранено');
    expect(Listing::query()->value('description_ru'))->toBe('Царапина на рамке')->and(Listing::query()->first()->badges)->toContain('hit');

    $c->call('pin', true)->assertSee('Закреплён №1');
    expect(Listing::query()->value('pinned_rank'))->toBe(1);

    $c->call('siteToggle')->assertSee('Скрыт вручную');
    expect(Listing::query()->count())->toBe(0);
});

it('a seller adds photos but cannot show the phone; the section «Сайт» shows reasons', function () {
    $w = world();
    $unit = $w->phone($w->usedIphone, Money::kzt(150000), ['condition' => 'used', 'grade' => 'B', 'sale_price' => Money::kzt(199000), 'show_on_site' => false]);
    $this->actingAs($w->seller);

    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->set('tabName', 'site')
        ->set('uploads', [sitePhoto()])->assertSee('Скрыт вручную')
        ->assertSee('Показать телефон на сайте может владелец или админ');
    try {
        Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->call('siteToggle');
    } catch (Throwable) {
    }
    expect($unit->refresh()->show_on_site)->toBeFalse();

    $this->actingAs($w->owner);
    Livewire::test(Site::class)->set('tab', 'goods')->assertSee($unit->public_code)->assertSee('Скрыт вручную')
        ->set('selected', [(string) $unit->id])->call('bulk', true)->assertSee('Показано на сайте: 1');
    expect(Listing::query()->count())->toBe(1);
});

it('works a site request found by its code: take, close', function () {
    $w = world();
    $unit = $w->phone();
    $lead = app(Leads::class)->create('ask', $unit->public_code, [], $w->store->id, 'ru', 'site', '/phone/'.$unit->public_code, 549000);
    $this->actingAs($w->seller);

    $c = Livewire::withQueryParams(['lead' => 'СЗ-'.$lead->seq])->test(Site::class)
        ->assertSet('tab', 'leads')->assertSee($lead->number)->assertSee('В наличии')
        ->call('takeLead')->assertSee('Заявка взята в работу');
    expect($lead->refresh()->status)->toBe('in_work')->and($lead->taken_by)->toBe($w->seller->id);

    $c->call('closeLead')->assertSee('Выберите итог')
        ->set('close.outcome', 'expensive')->call('closeLead')->assertSee('Заявка закрыта');
    expect($lead->refresh()->status)->toBe('closed')->and($lead->outcome)->toBe('expensive');

    $results = app(ErpSearch::class)->getResults($lead->number);
    expect($results->getCategories()->keys()->all())->toContain('Заявки с сайта');
});

it('hides a model with a count, saves stores, pages and settings; the site cannot open with a draft policy', function () {
    $w = world();
    $unit = $w->phone($w->usedIphone, Money::kzt(150000), ['condition' => 'used', 'grade' => 'B', 'sale_price' => Money::kzt(199000)]);
    $this->actingAs($w->owner);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], sitePhoto(), $w->owner);
    app(ListingBuilder::class)->units([$unit->id]);

    $c = Livewire::test(Site::class)->set('tab', 'rules')
        ->call('askHide', 'model', $w->usedIphone->model_id)->assertSee('Скрыть? Уйдут 1')
        ->call('setHidden', 'model', $w->usedIphone->model_id, true);
    expect(ProductModel::query()->find($w->usedIphone->model_id)->site_hidden)->toBeTrue()->and(Listing::query()->count())->toBe(0);
    $c->call('setHidden', 'model', $w->usedIphone->model_id, false);
    expect(Listing::query()->count())->toBe(1);

    $c->set("storesForm.{$w->store->id}.whatsapp", '+77017770707')->set("storesForm.{$w->store->id}.hours", '10:00–21:00')->call('saveStores')->assertSee('Магазины сохранены');
    expect(SiteStore::query()->value('whatsapp'))->toBe('+77017770707');

    $c->set('tab', 'settings')->set('settings.enabled', true)->call('saveSettings')->assertSee('черновик');
    expect(SiteSettings::current()->enabled())->toBeFalse();

    $c->set('tab', 'pages')->call('editPage', 'privacy')->set('pageForm.body_ru', 'Текст юриста')->set('pageForm.is_draft', false)->call('savePage')->assertSee('Страница сохранена');
    expect(SitePage::query()->where('slug', 'privacy')->value('is_draft'))->toBeFalse();

    $c->set('tab', 'settings')->set('settings.enabled', true)->set('settings.installment.months', '3, 6, 12')->set('settings.installment.markup', '0, 0, 5')
        ->call('saveSettings')->assertSee('Настройки сохранены');
    CurrentOrganization::instance()->set($w->org->refresh());
    expect(SiteSettings::current()->enabled())->toBeTrue()
        ->and(SiteSettings::current()->get('installment.months'))->toBe([3, 6, 12])
        ->and(Listing::query()->value('installment_months'))->toBe(12)
        ->and(Listing::query()->value('installment_from'))->toBe((int) ceil(199000 * 1.05 / 12));

    $c->set('tab', 'banners')->call('editBanner')->set('banner.title_ru', 'iPhone 17 Pro Max')->set('banner.link', '/catalog/smartfony')->call('saveBanner')->assertSee('Баннер сохранён');
    $c->set('tab', 'state')->assertSee('На сайте сейчас')->call('rebuild')->assertSee('Сайт пересобран');
    $c->set('tab', 'stats')->assertSee('Ищут и не находят');
});
