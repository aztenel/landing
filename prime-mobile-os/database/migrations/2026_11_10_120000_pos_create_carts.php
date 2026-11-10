<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carts of the cash register (TZ 04 Э3.3 «Чек 1 · Чек 2 · +»): kept on the server, so a reload, a second device or a
 * pending approval loses nothing. Only ids, quantities, discounts and payments are stored — prices always come from
 * the price list at the moment of showing and posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('tab')->default(1);
            $table->uuid('key');                                   // a new one after every posted check
            $table->unsignedInteger('version')->default(0);         // +1 on every change: the draft key of a payment
            $table->jsonb('data');
            $table->foreignId('draft_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->foreignId('last_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id', 'store_id', 'tab']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_carts');
    }
};
