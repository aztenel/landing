<?php

namespace Database\Seeders;

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\Banner;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SitePage;
use App\Domain\Storefront\Services\ListingBuilder;
use App\Domain\Storefront\Services\Photos;
use App\Domain\Storefront\Services\SitePages;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Demo / test server only (deploy/scripts/demo-install.sh), after PrimeMobileSeeder: turns the site on and gives the
 * demo phones placeholder photos (a coloured body with cameras, not real photos), banners and the privacy page, so
 * the shop is not empty on a fresh server. Never on the shop's real server.
 */
class DemoSiteSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->where('name', 'Prime Mobile')->firstOrFail();
        $owner = User::query()->where('phone', '+77010000001')->firstOrFail();
        $settings = $org->settings ?? [];
        $settings['storefront']['enabled'] = true;
        $settings['storefront']['city_ru'] = $settings['storefront']['city_kk'] = 'Астана';   // the demo shop is in Astana
        $org->settings = $settings;
        $org->save();
        CurrentOrganization::instance()->set($org->refresh());

        SitePage::query()->updateOrCreate(['slug' => 'privacy'], [
            'title_ru' => 'Политика конфиденциальности', 'body_ru' => SitePages::DEFAULTS['privacy']['body'], 'is_draft' => false,
        ]);
        Store::query()->first()?->update(['phone' => '+7 701 000 00 01', 'meta' => [
            'whatsapp' => '+77010000001', 'hours' => 'Ежедневно 10:00–21:00', 'map_url' => 'https://2gis.kz/astana', 'city' => 'Астана',
        ]]);

        $photos = app(Photos::class);
        $done = [];
        foreach (StockUnit::query()->with('variant.color')->whereIn('status', ['in_stock', 'reserved', 'sold'])->get() as $unit) {
            $hex = $unit->variant?->color?->hex ?: '#6b7280';
            if ($unit->condition->value === 'used') {          // a used phone: its own photos
                for ($i = 0; $i < 3; $i++) {
                    $photos->upload(['stock_unit_id' => $unit->id], $this->shot($hex, $i), $owner);
                }
            } elseif (! isset($done[$key = $unit->variant->model_id.':'.$unit->variant->color_id])) {   // new: per model + colour
                $done[$key] = true;
                for ($i = 0; $i < 2; $i++) {
                    $photos->upload(['model_id' => $unit->variant->model_id, 'color_id' => $unit->variant->color_id], $this->shot($hex, $i), $owner);
                }
            }
        }

        Banner::query()->delete();
        Banner::query()->create(['template' => 'tradein', 'title_ru' => 'Trade-in вашего iPhone', 'subtitle_ru' => 'Сдайте старый iPhone и заберите новый. Доплачиваете только разницу.',
            'button_ru' => 'Подробнее', 'link' => '/trade-in', 'sort' => 1, 'is_active' => true]);
        $banner = Banner::query()->create(['template' => 'new', 'title_ru' => 'Новые iPhone', 'subtitle_ru' => 'Уже в Prime Mobile',
            'button_ru' => 'Смотреть', 'link' => '/catalog/smartfony', 'sort' => 2, 'is_active' => true]);

        app(ListingBuilder::class)->all();
        $banner->update(['price_model_ref' => Listing::query()->where('state', 'new')->value('model_ref')]);
        ListingBuilder::touch();
    }

    /** A 1200×1200 placeholder: the phone body in its colour with three cameras. */
    private function shot(string $hex, int $i): UploadedFile
    {
        $im = imagecreatetruecolor(1200, 1200);
        imagefill($im, 0, 0, imagecolorallocate($im, 243, 244, 246));
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
        $body = imagecolorallocate($im, $r ?? 75, $g ?? 85, $b ?? 99);
        $dark = imagecolorallocate($im, 30, 30, 34);
        $x = 330 + ($i % 2) * 40;
        imagefilledrectangle($im, $x, 160, $x + 540, 1040, $body);
        imagefilledellipse($im, $x + 120, 290, 150, 150, $dark);
        imagefilledellipse($im, $x + 120, 450, 150, 150, $dark);
        imagefilledellipse($im, $x + 260, 370, 150, 150, $dark);
        ob_start();
        imagejpeg($im, null, 88);

        return UploadedFile::fake()->createWithContent("demo-$i.jpg", (string) ob_get_clean());
    }
}
