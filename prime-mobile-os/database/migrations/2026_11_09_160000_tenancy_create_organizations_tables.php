<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy: organizations (= owner accounts), stores, memberships, role extras and role limits.
 * Architecture §2.1, TZ 03 §1, §5, §12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('bin_iin', 12)->nullable();
            $table->jsonb('requisites')->default('{}');   // bank, address, director, phones…
            $table->jsonb('settings')->default('{}');     // see OrganizationSettings::DEFAULTS
            $table->string('timezone', 64)->default('Asia/Almaty');
            $table->date('closed_until')->nullable();     // TZ 02 §9.1: everything up to this date (inclusive) is closed
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->jsonb('store_ids')->default('[]');    // stores the member works in (scope «свой магазин»)
            $table->jsonb('limits')->default('{}');       // personal limits, TZ 03 §5.3: {"L1": 1000000}
            $table->string('position')->nullable();
            $table->date('started_on')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('invited','active','blocked','dismissed'))");

        // Spatie roles are per organization (teams = organizations). Add a human title and the owner flag.
        Schema::table('roles', function (Blueprint $table) {
            $table->string('title')->nullable()->after('name');
            $table->boolean('is_owner')->default(false)->after('title');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // Role limits (TZ 03 §5.1). code = 'L1'..'L18' or a permission name with value 0 = «только заявкой».
        // value NULL = unlimited (∞), 0 = always approval. Money in tiyn, percent ×100, days as is.
        Schema::create('role_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('code', 64);
            $table->bigInteger('value')->nullable();
            $table->timestampsTz();
            $table->unique(['role_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_limits');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['title', 'is_owner']);
        });
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('organizations');
    }
};
