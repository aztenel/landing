<?php

use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Inventory\Services\PriceService;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Storefront\Models\Lead;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Services\Photos;
use App\Domain\Storefront\Services\Visibility;
use App\Domain\Storefront\Support\SiteChange;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** A JPEG 1200×900 with an EXIF block that carries a «geolocation». */
function exifJpeg(): UploadedFile
{
    $im = imagecreatetruecolor(1200, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 205, 215));
    ob_start();
    imagejpeg($im, null, 90);
    $jpeg = ob_get_clean();
    $payload = "Exif\0\0".'GPSLatitude 43.238949 HOME-ADDRESS-SECRET';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return UploadedFile::fake()->createWithContent('IMG_0001.jpg', substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
}

function syncSite(): void
{
    app(OutboxDispatcher::class)->run(500);
}

function enableSite($w, array $extra = []): void
{
    $w->org->settings = array_replace_recursive($w->org->settings ?? [], ['storefront' => ['enabled' => true] + $extra]);
    $w->org->save();
    CurrentOrganization::instance()->set($w->org->refresh());
    $w->store->update(['phone' => '+77010000001', 'meta' => ['whatsapp' => '+77015550505', 'hours' => '10:00–21:00']]);
    SiteChange::all('test');
    syncSite();
}

function usedPhone($w)
{
    return $w->phone($w->usedIphone, Money::kzt(150000), ['condition' => 'used', 'grade' => 'B', 'battery_percent' => 87, 'sale_price' => Money::kzt(219000)]);
}

beforeEach(function () {
    config(['app.url' => 'http://localhost']);   // the public site answers on the shop domain (TZ 05 §1)
    URL::forceRootUrl('http://localhost');
    Storage::fake('public');
    Storage::fake('local');
});

it('shows a used phone after its first photo, strips EXIF, hides IMEI and cost, marks it sold', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    syncSite();

    expect(collect(Visibility::reasons($unit))->pluck('code')->all())->toBe(['photo'])
        ->and(Listing::query()->count())->toBe(0);

    $photo = app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    syncSite();

    $l = Listing::query()->where('public_code', $unit->public_code)->firstOrFail();
    expect($photo->status)->toBe('ready')
        ->and($l->price)->toBe(219000)
        ->and($l->state)->toBe('used')
        ->and($l->installment_from)->toBe(9125)                       // TZ 05 §7.2: 219 000 / 24 = 9 125
        ->and($l->installment_months)->toBe(24)
        ->and($l->grade)->toBe('B')
        ->and($l->imei_verified)->toBeTrue();
    foreach ($photo->files as $size => $path) {
        $bytes = Storage::disk('public')->get($path);
        expect($bytes)->not->toContain('HOME-ADDRESS-SECRET')->not->toContain('Exif');
        expect($path)->not->toContain($unit->public_code);
    }
    expect(Storage::disk('local')->get($photo->original_path))->toContain('HOME-ADDRESS-SECRET');   // the private original keeps it

    $html = $this->get('/phone/'.$unit->public_code)->assertOk()->getContent();
    expect($html)->toContain($unit->public_code)->toContain('219')->toContain('Хорошее')
        ->not->toContain($unit->imei1)->not->toContain('себестоимост')->not->toContain('Dubai Mobile');
    expect(preg_match('/\d{14,}/', strip_tags($html)))->toBe(0);
    $this->get('/')->assertOk()->assertSee($unit->public_code === '' ? 'x' : 'iPhone 13');
    $this->get('/bu')->assertOk()->assertSee('iPhone 13');

    SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(219000))->cash(Money::kzt(219000))->post($w->owner);
    syncSite();

    expect($l->refresh()->public_status)->toBe('sold');
    $this->get('/bu')->assertOk()->assertDontSee('iPhone 13 128');
    $this->get('/phone/'.$unit->public_code)->assertOk()->assertSee('Продан')->assertSee('Подберите мне похожий');
});

it('folds identical new phones and uses the model photo', function () {
    $w = world();
    enableSite($w);
    $a = $w->phone();
    $b = $w->phone();
    app(Photos::class)->upload(['model_id' => $w->iphone->model_id, 'color_id' => $w->iphone->color_id], exifJpeg(), $w->owner);
    syncSite();

    expect(Listing::query()->where('state', 'new')->count())->toBe(2);
    $this->get('/catalog/smartfony')->assertOk()->assertSee('в наличии 2');
    $this->get('/phone/'.$a->public_code)->assertOk()->assertSee('В наличии 2 шт.');
});

it('hides a whole model and brings it back', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    syncSite();
    expect(Listing::query()->count())->toBe(1);

    ProductModel::query()->whereKey($w->usedIphone->model_id)->update(['site_hidden' => true]);
    SiteChange::all('model');
    syncSite();
    expect(Listing::query()->count())->toBe(0)
        ->and(collect(Visibility::reasons($unit->refresh()))->pluck('code')->all())->toBe(['model']);

    ProductModel::query()->whereKey($w->usedIphone->model_id)->update(['site_hidden' => false]);
    SiteChange::all('model');
    syncSite();
    expect(Listing::query()->count())->toBe(1);
});

it('shows the old price crossed out after a price drop', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    app(PriceService::class)->setUnitPrice($unit->refresh(), Money::kzt(205000), $w->owner, 'manual', true);
    syncSite();

    $l = Listing::query()->firstOrFail();
    expect($l->price)->toBe(205000)->and($l->compare_at_price)->toBe(219000)->and($l->discountPercent())->toBe(6)
        ->and($l->badges)->toContain('sale');
});

it('creates an anonymous request and opens WhatsApp of the store; a sale closes it', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    syncSite();

    $r = $this->get('/kk/go/wa?t=ask&code='.$unit->public_code);
    $r->assertRedirect();
    $to = $r->headers->get('Location');
    $lead = Lead::query()->firstOrFail();
    expect($to)->toStartWith('https://wa.me/77015550505?text=')
        ->and(rawurldecode($to))->toContain($unit->public_code)->toContain('СЗ-1001')
        ->and($lead->number)->toBe('СЗ-1001')->and($lead->lang)->toBe('kk')->and($lead->status)->toBe('clicked')->and($lead->price)->toBe(219000);

    SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(219000))->cash(Money::kzt(219000))->post($w->owner);
    syncSite();
    expect($lead->refresh()->status)->toBe('closed')->and($lead->outcome)->toBe('bought');
});

it('searches by slang, layout and code; shows Kazakh texts', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    syncSite();

    $this->get('/search?q=айфон 13')->assertOk()->assertSee('iPhone 13');
    $this->get('/search?q=шзрщту')->assertOk()->assertSee('iPhone 13');
    $this->get('/search?q=samsung')->assertOk()->assertSee('Спросите в WhatsApp');
    $this->get('/search?q='.$unit->public_code)->assertRedirect('/phone/'.$unit->public_code);
    $this->get('/'.strtolower($unit->public_code))->assertRedirect('/phone/'.strtoupper($unit->public_code));
    $this->get('/kk/phone/'.$unit->public_code)->assertOk()->assertSee('Жақсы')->assertSee('WhatsApp-қа жазу');
    $this->get('/catalog/smartfony?state=used&bat=80')->assertOk()->assertSee('iPhone 13');
    $this->get('/catalog/smartfony?state=used&bat=90')->assertOk()->assertSee('Ничего не нашли');
});

it('keeps a switched-off site closed to buyers and never indexed', function () {
    $w = world();
    $this->get('/')->assertOk()->assertSee('Сайт скоро откроется');
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
    $this->get('/no-such-page')->assertStatus(404);
});

it('puts a seller photo of a visible phone on review and lets the nightly check fix a broken card', function () {
    $w = world();
    enableSite($w);
    $unit = usedPhone($w);
    app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->owner);
    syncSite();

    $p = app(Photos::class)->upload(['stock_unit_id' => $unit->id], exifJpeg(), $w->seller);
    expect($p->needs_review)->toBeTrue();

    Listing::query()->update(['price' => 1]);
    $this->artisan('prime:site-reconcile')->assertSuccessful();
    expect(Listing::query()->value('price'))->toBe(219000)
        ->and(DB::table('storefront_runs')->value('fixed'))->toBe(1);
});
