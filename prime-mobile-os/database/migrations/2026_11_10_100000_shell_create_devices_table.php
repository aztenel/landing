<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devices of the organization (TZ 03 §8, TZ 04 Э25.4, Э29). A device = a browser with a random key in a
 * cookie (+ copy in localStorage); only the SHA-256 of the key is stored. Devices are per organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->string('name')->nullable();                  // «Ноутбук касса», «iPhone Даны»
            $table->string('type', 16)->default('other');        // store_computer | personal | other
            $table->foreignId('store_id')->nullable();           // store computer → its store
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete(); // personal device
            $table->string('platform', 40)->nullable();          // Windows 7, iPhone, macOS…
            $table->string('browser', 40)->nullable();           // Chrome 109, Safari 26…
            $table->text('user_agent')->nullable();
            $table->string('status', 16)->default('allowed');    // allowed | pending | disabled
            $table->boolean('legacy_mode')->default(false);      // «старый ноутбук» (Э29)
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'key_hash']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_status_check CHECK (status IN ('allowed','pending','disabled'))");
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_type_check CHECK (type IN ('store_computer','personal','other'))");
        // A store computer belongs to a store of the same firm (TZ 03 §12; CORE §11 composite FK).
        DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_store_id_same_org_fk FOREIGN KEY (organization_id, store_id) REFERENCES stores (organization_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
