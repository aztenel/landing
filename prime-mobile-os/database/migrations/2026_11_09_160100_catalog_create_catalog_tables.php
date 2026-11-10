<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog (architecture §2.2). The model directory is GLOBAL (assumption Д1: shared by all organizations),
 * prices are per organization (variant_prices, accessory_prices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();          // smartphone, tablet, watch, headphones…
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->boolean('is_serialized')->default(true); // sold per unit by IMEI/serial
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->string('brand', 64);                   // Apple, Samsung, Xiaomi
            $table->string('name');                        // iPhone, Galaxy S, Redmi Note
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['product_type_id', 'name']);
        });

        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['family_id', 'name_ru']);
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();           // «256 ГБ», «1 ТБ»
            $table->unsignedInteger('gb');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name_ru')->unique();           // shown as on the box: «Desert Titanium»
            $table->string('name_kk')->nullable();
            $table->string('name_en')->nullable();
            $table->string('hex', 7)->nullable();
            $table->timestampsTz();
        });

        Schema::create('rams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();           // «8 ГБ»
            $table->unsignedInteger('gb');
            $table->timestampsTz();
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('models')->restrictOnDelete();
            $table->foreignId('memory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ram_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sku', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX variants_combination_unique ON variants (model_id, memory_id, color_id, ram_id) NULLS NOT DISTINCT');

        // accessories and their categories belong to a firm (audit №9); only phone models are shared (Д1)
        Schema::create('accessory_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();   // overrides the firm default (audit №32)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name_ru']);
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('sku', 64);                     // артикул
            $table->string('barcode', 64)->nullable();
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'barcode']);
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'category_id'])->references(['organization_id', 'id'])->on('accessory_categories')->restrictOnDelete();
        });

        Schema::create('variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('price_kzt');                // tiyn
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'variant_id']);
        });

        Schema::create('accessory_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('price_kzt');                // tiyn
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'accessory_id']);
            $table->foreign(['organization_id', 'accessory_id'], 'accessory_prices_same_org_fk')->references(['organization_id', 'id'])->on('accessories')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_positive CHECK (price_kzt >= 0)');
        DB::statement('ALTER TABLE accessory_prices ADD CONSTRAINT accessory_prices_positive CHECK (price_kzt >= 0)');
    }

    public function down(): void
    {
        foreach (['accessory_prices', 'variant_prices', 'accessories', 'accessory_categories', 'variants', 'rams', 'colors', 'memories', 'models', 'families', 'product_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
