<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (bell, TZ 04 Э28.1), per-user channel preferences («что присылать владельцу», Э26) and
 * Telegram links (TZ 01 П9 p.2: the Telegram copy of pushes). Telegram gets no personal data (TZ 06 §4.2 p.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();       // recipient
            $table->string('type', 48);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->boolean('silent')->default(false);                            // feed only, no push / Telegram
            $table->string('push_status', 16)->nullable();                        // sent | skipped | failed
            $table->string('telegram_status', 16)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'user_id', 'read_at', 'id']);
            $table->index('approval_request_id');
        });
        DB::statement('ALTER TABLE app_notifications ADD CONSTRAINT app_notifications_request_same_org_fk
            FOREIGN KEY (organization_id, approval_request_id) REFERENCES approval_requests (organization_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE app_notifications ADD CONSTRAINT app_notifications_document_same_org_fk
            FOREIGN KEY (organization_id, document_id) REFERENCES documents (organization_id, id) ON DELETE RESTRICT');

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->boolean('in_app')->default(true);
            $table->boolean('push')->default(true);
            $table->boolean('telegram')->default(false);
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id', 'type']);
        });

        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id', 32)->nullable();
            $table->string('link_token', 64)->nullable()->unique();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('linked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_links');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('app_notifications');
    }
};
