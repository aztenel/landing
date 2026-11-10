<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 03 §7: 2FA by an authenticator app (secret encrypted, 10 recovery codes hashed), «запомнить этот iPhone на 30 дней»
 * (personal devices only), PIN of unlock — own for every user on every device (hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestampTz('two_factor_confirmed_at')->nullable();
        });
        Schema::create('two_factor_trusts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('device_id')->nullable();
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at');
        });
        Schema::create('device_pins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('device_id')->constrained();
            $t->string('pin_hash');
            $t->timestampsTz();
            $t->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pins');
        Schema::dropIfExists('two_factor_trusts');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
