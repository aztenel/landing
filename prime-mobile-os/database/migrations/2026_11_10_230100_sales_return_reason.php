<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ТЗ 00-kazakhstan §9: the reason of a return line (брак / не подошёл / онлайн ≤ 14 дней / …). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_lines', fn (Blueprint $t) => $t->string('reason', 16)->nullable());
    }

    public function down(): void
    {
        Schema::table('customer_return_lines', fn (Blueprint $t) => $t->dropColumn('reason'));
    }
};
