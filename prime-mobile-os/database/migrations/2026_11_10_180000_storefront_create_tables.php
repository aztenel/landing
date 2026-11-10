<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront (TZ 05): the public catalog site reads only the storefront_* tables (a safe copy, §2) and writes only
 * site_leads / site_stats (§11, §13.5). ERP side: per-unit site fields, levels of visibility (§4.2), photos (§3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $t) {
            $t->text('site_description_ru')->nullable();
            $t->text('site_description_kk')->nullable();
            $t->bigInteger('compare_at_price')->nullable();        // tiyn, «цена до скидки» (§6.2)
            $t->jsonb('site_badges')->nullable();                  // ["hit"]
            $t->smallInteger('site_pinned_rank')->nullable();      // 1..12 (§5.2)
            $t->date('site_pinned_until')->nullable();
            $t->foreignId('site_hidden_by')->nullable();
            $t->timestampTz('site_hidden_at')->nullable();
        });
        foreach (['models', 'families', 'product_types'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('site_hidden')->default(false));
        }
        Schema::table('accessories', function (Blueprint $t) {
            $t->boolean('show_on_site')->default(true);
            $t->boolean('site_can_order')->default(false);         // «можно под заказ» (§2.2)
        });

        Schema::create('site_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('stock_unit_id')->nullable()->constrained();
            $t->foreignId('model_id')->nullable();                 // photo of a model + colour (new sealed, §3.6)
            $t->foreignId('color_id')->nullable();
            $t->foreignId('accessory_id')->nullable();
            $t->integer('sort')->default(0);
            $t->string('status', 16)->default('processing');       // processing | ready | blocked | failed
            $t->string('original_path')->nullable();               // private disk
            $t->jsonb('files')->nullable();                        // size => public path (random names)
            $t->jsonb('flags')->nullable();                        // ["dark"], ["small"] — hints (§3.5)
            $t->string('error')->nullable();
            $t->foreignId('uploaded_by')->nullable();
            $t->boolean('needs_review')->default(false);           // seller photo on a unit already on the site
            $t->foreignId('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampTz('deleted_at')->nullable();             // files are kept 30 days (§3.7)
            $t->timestampsTz();
            $t->index(['organization_id', 'stock_unit_id']);
            $t->index(['organization_id', 'model_id', 'color_id']);
        });

        Schema::create('storefront_listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->bigInteger('unit_ref')->index();                   // rebuild key, never rendered
            $t->string('public_code', 20);
            $t->string('slug', 160);
            $t->string('state', 8);                                // new | used
            $t->string('type_code', 32)->nullable();
            $t->string('brand', 64)->nullable();
            $t->string('brand_slug', 64)->nullable();
            $t->bigInteger('model_ref')->nullable();
            $t->string('model_name', 120)->nullable();
            $t->string('model_slug', 120)->nullable();
            $t->bigInteger('variant_ref')->nullable();
            $t->string('title_ru', 200);
            $t->string('title_kk', 200);
            $t->integer('memory_gb')->nullable();
            $t->string('memory', 20)->nullable();
            $t->string('ram', 20)->nullable();
            $t->string('color_ru', 60)->nullable();
            $t->string('color_kk', 60)->nullable();
            $t->string('color_hex', 9)->nullable();
            $t->char('grade', 1)->nullable();
            $t->smallInteger('battery_pct')->nullable();
            $t->smallInteger('release_year')->nullable();
            $t->text('description_ru')->nullable();
            $t->text('description_kk')->nullable();
            $t->jsonb('public_checks')->nullable();
            $t->boolean('imei_verified')->default(false);
            $t->integer('price');                                  // ₸ (whole tenge)
            $t->integer('compare_at_price')->nullable();
            $t->jsonb('badges')->nullable();
            $t->integer('installment_from')->nullable();
            $t->smallInteger('installment_months')->nullable();
            $t->smallInteger('warranty_months')->nullable();
            $t->string('public_status', 12);                       // available | reserved | sold
            $t->bigInteger('store_ref');
            $t->jsonb('photos')->nullable();
            $t->smallInteger('pinned_rank')->nullable();
            $t->bigInteger('sort_rank')->default(0);
            $t->timestampTz('published_at');
            $t->timestampTz('sold_at')->nullable();
            $t->string('group_key', 120);
            $t->text('search_text');
            $t->timestampTz('updated_at');
            $t->unique(['organization_id', 'public_code']);
            $t->index(['organization_id', 'public_status', 'state']);
        });

        Schema::create('storefront_accessories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->bigInteger('accessory_ref')->index();
            $t->string('public_code', 20);
            $t->string('slug', 160);
            $t->string('title_ru', 200);
            $t->string('title_kk', 200);
            $t->string('category_ru', 120)->nullable();
            $t->string('category_kk', 120)->nullable();
            $t->string('category_slug', 120)->nullable();
            $t->integer('price');
            $t->string('availability', 12);                        // in_stock | to_order
            $t->jsonb('photos')->nullable();
            $t->text('search_text');
            $t->timestampTz('updated_at');
            $t->unique(['organization_id', 'public_code']);
        });

        Schema::create('storefront_stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->bigInteger('store_ref');
            $t->string('slug', 80);
            $t->string('name', 120);
            $t->string('address_ru')->nullable();
            $t->string('address_kk')->nullable();
            $t->string('hours')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('whatsapp', 20)->nullable();
            $t->string('map_url')->nullable();
            $t->string('city', 60)->nullable();
            $t->boolean('is_main')->default(false);
            $t->timestampTz('updated_at');
            $t->unique(['organization_id', 'store_ref']);
        });

        Schema::create('storefront_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('slug', 40);                                // trade-in | rassrochka | garantiya | privacy | about
            $t->string('title_ru');
            $t->string('title_kk')->nullable();
            $t->text('body_ru')->nullable();
            $t->text('body_kk')->nullable();
            $t->boolean('is_draft')->default(true);
            $t->foreignId('updated_by')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('storefront_banners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('template', 16);                            // new | tradein | used | promo
            $t->string('title_ru', 40);
            $t->string('title_kk', 40)->nullable();
            $t->string('subtitle_ru', 80)->nullable();
            $t->string('subtitle_kk', 80)->nullable();
            $t->string('button_ru', 20)->nullable();
            $t->string('button_kk', 20)->nullable();
            $t->string('link', 200);                               // site path: /model/iphone-15-pro, /trade-in …
            $t->bigInteger('price_model_ref')->nullable();         // «цена авто» = min visible price of the model
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });

        Schema::create('site_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->integer('seq');
            $t->string('number', 16);                              // СЗ-1001
            $t->string('type', 16);                                // ask | installment | book | similar | to_order | tradein
            $t->string('public_code', 20)->nullable();
            $t->integer('price')->nullable();                      // ₸ at the moment of the click
            $t->jsonb('payload')->nullable();
            $t->bigInteger('store_id')->nullable();
            $t->string('lang', 2)->default('ru');
            $t->string('source', 40)->nullable();                  // direct | google | instagram | utm
            $t->string('page', 200)->nullable();
            $t->string('status', 12)->default('clicked');          // clicked | in_work | closed
            $t->foreignId('taken_by')->nullable();
            $t->timestampTz('taken_at')->nullable();
            $t->string('outcome', 20)->nullable();                 // bought | no_answer | changed_mind | expensive | not_available | other
            $t->text('comment')->nullable();
            $t->foreignId('result_document_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'seq']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('site_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->date('day');
            $t->string('kind', 12);                                // view | click | miss
            $t->string('key', 120);                                // public code or search text
            $t->integer('count')->default(0);
            $t->unique(['organization_id', 'day', 'kind', 'key']);
        });

        Schema::create('storefront_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('kind', 12);                                // reconcile | rebuild
            $t->integer('fixed')->default(0);
            $t->jsonb('details')->nullable();
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['storefront_runs', 'site_stats', 'site_leads', 'storefront_banners', 'storefront_pages', 'storefront_stores', 'storefront_accessories', 'storefront_listings', 'site_photos'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('accessories', fn (Blueprint $t) => $t->dropColumn(['show_on_site', 'site_can_order']));
        foreach (['models', 'families', 'product_types'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('site_hidden'));
        }
        Schema::table('stock_units', fn (Blueprint $t) => $t->dropColumn(['site_description_ru', 'site_description_kk', 'compare_at_price', 'site_badges', 'site_pinned_rank', 'site_pinned_until', 'site_hidden_by', 'site_hidden_at']));
    }
};
