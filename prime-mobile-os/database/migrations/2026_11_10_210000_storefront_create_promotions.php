<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Promotions (TZ 05 §6.3): change many prices for a period through price-change documents, then put them back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('name_ru', 120);
            $t->string('name_kk', 120)->nullable();
            $t->string('label_ru', 40)->nullable();
            $t->string('label_kk', 40)->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->jsonb('filter');                          // type_code, model_ids, condition, codes
            $t->string('discount_type', 8);               // percent | amount | fixed
            $t->decimal('discount_value', 14, 2);
            $t->integer('rounding')->default(1000);       // ₸: 1000 | 100 | 1 (down)
            $t->boolean('show_old')->default(true);
            $t->string('status', 12)->default('planned'); // planned | active | finished | cancelled
            $t->foreignId('created_by');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('promotion_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('promotion_id')->constrained();
            $t->foreignId('stock_unit_id');
            $t->bigInteger('price_before')->nullable();   // tiyn; null — the unit had the variant price
            $t->bigInteger('compare_before')->nullable();
            $t->bigInteger('price_promo');
            $t->string('result', 12)->nullable();         // restored | manual (changed during the promo — «Решите сами») | sold
            $t->unique(['promotion_id', 'stock_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
