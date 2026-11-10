<?php

use App\Domain\Storefront\Models\Banner;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Tenancy\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSiteSeeder;

it('fills the demo site for a test server: site on, placeholder photos, banners, listings with prices', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSiteSeeder::class);

    expect(Organization::query()->where('name', 'Prime Mobile')->first()->settings['storefront']['enabled'] ?? false)->toBeTrue()
        ->and(Banner::query()->where('is_active', true)->count())->toBe(2)
        ->and(Banner::query()->where('template', 'new')->value('price_model_ref'))->not->toBeNull()
        ->and(Listing::query()->count())->toBeGreaterThan(10)
        ->and(Listing::query()->where('price', '>', 0)->count())->toBeGreaterThan(10);

    $this->get('http://'.config('prime.shop_domain').'/')->assertOk()->assertSee('Trade-in вашего iPhone')->assertSee('Астана');
});
