<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and settings fields of the shell (TZ 03 §7, §14, §15; TZ 04 Э25, Э26). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);   // Э1.2: first login — own password
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->jsonb('payroll')->default('{}');            // % of profit: {"phones": "5.00", "accessories": "10.00"}
            $table->text('blocked_reason')->nullable();         // visible to owner/admin only (TZ 03 §15.1)
            $table->timestampTz('blocked_at')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rules_accepted_at')->nullable(); // Э1.2: consent with the client-data rules
            $table->unsignedInteger('rules_version')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_device', 120)->nullable();
            $table->boolean('hints_seen')->default(false);      // TZ 04 §0.4 п. 13
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('description')->nullable();          // «касса и клиенты» under the role name (Э25.2)
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->jsonb('meta')->default('{}');               // address_kk, map_url, whatsapp, hours, site_hide (Э26 «Магазины»)
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('meta'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn([
            'payroll', 'blocked_reason', 'blocked_at', 'dismissal_reason', 'invited_at', 'accepted_at',
            'rules_accepted_at', 'rules_version', 'last_login_at', 'last_login_device', 'hints_seen',
        ]));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['must_change_password', 'password_changed_at', 'last_login_at']));
    }
};
