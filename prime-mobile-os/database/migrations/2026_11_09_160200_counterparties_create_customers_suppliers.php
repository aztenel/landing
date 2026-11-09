<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counterparties: customers and suppliers (a repair master is a supplier too). Architecture §2.9, TZ 03 §17.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('phone', 20)->nullable();         // NULL after anonymization
            $table->string('name')->nullable();
            $table->text('iin')->nullable();                 // encrypted (cast)
            $table->string('iin_hash', 64)->nullable();      // blind index HMAC-SHA256 for search
            $table->string('iin_last4', 4)->nullable();      // for the mask •••• •••• 1234
            $table->timestampTz('pd_consent_at')->nullable();
            $table->string('pd_consent_method', 32)->nullable();  // pos_checkbox, tradein_signature, site
            $table->string('pd_consent_version', 32)->nullable();
            $table->string('source', 32)->nullable();        // pos, site, sfon_import…
            $table->text('comment')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'phone']);
            $table->index(['organization_id', 'iin_hash']);
            $table->index(['organization_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('bin_iin', 12)->nullable();
            $table->string('contact_person')->nullable();
            $table->boolean('is_repair_master')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};
