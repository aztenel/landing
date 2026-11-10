<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (audit №5): a change that the site (and later Telegram, reports cache) must learn about is
 * written in the SAME transaction as the change itself; a worker delivers it afterwards with retries. An event that
 * happened after a crash between commit and «afterCommit» is therefore never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('topic', 48);                        // document.posted, document.reversed, unit.changed
            $table->string('subject_type', 32)->nullable();     // document, stock_unit
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('available_at')->useCurrent();  // next attempt not before
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
        });
        \Illuminate\Support\Facades\DB::statement('CREATE INDEX outbox_events_pending ON outbox_events (available_at, id) WHERE processed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
