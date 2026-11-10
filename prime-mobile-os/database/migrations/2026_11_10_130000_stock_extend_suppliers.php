<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Э23 «Поставщики»: currency of settlement, default payment term, city, WhatsApp, requisites. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->char('currency', 3)->default('KZT');
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->text('requisites')->nullable();
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_currency_check CHECK (currency IN ('KZT','USD'))");
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['currency', 'payment_days', 'city', 'whatsapp', 'requisites']));
    }
};
