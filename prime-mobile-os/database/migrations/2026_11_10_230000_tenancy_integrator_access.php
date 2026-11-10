<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TZ 03 §13: a temporary «техподдержка» access of the integrator — a membership that ends by itself. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            $t->timestampTz('support_until')->nullable();
            $t->text('support_purpose')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn(['support_until', 'support_purpose']));
    }
};
