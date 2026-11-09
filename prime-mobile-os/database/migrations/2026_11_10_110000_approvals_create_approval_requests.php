<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests to the owner (TZ 03 §6, TZ 01 П9): one row per request. A request is bound to ONE operation:
 * a draft document (document_id), the original document of a reversal (subject_document_id) or a non-document
 * action (action_type + action_key). The fingerprint of the operation is stored at request time; an approval is
 * valid 15 minutes after the decision and only for one posting (used_document_id).
 *
 * documents.approval_request_id gets no FK on purpose: a draft may carry any id from the client — the gate
 * (DatabaseApprovalGate) checks that the request belongs to this document and the Poster drops an id that
 * authorized nothing (CORE.md §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 48);                                  // main requirement: discount, debt, return, …
            $table->string('status', 16)->default('pending');            // pending|approved|rejected|cancelled|expired|used|lapsed
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('subject_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('action_type', 48)->nullable();               // non-document actions (price change, export, …)
            $table->char('action_key', 64)->nullable();                  // sha256 of the action payload
            $table->jsonb('requirements');                               // what exceeded which limit (list)
            $table->jsonb('fingerprint');                                // group => sha256 of the operation data
            $table->jsonb('values')->nullable();                         // amounts of the operation at request time
            $table->string('headline', 200);                             // lock screen: «Дана просит разрешение: скидка»
            $table->text('summary');                                     // what is asked, without personal data
            $table->jsonb('context')->nullable();                        // card context (customer, seller's day), in-app only
            $table->jsonb('sensitive')->nullable();                      // cost / profit — only via ApprovalPresenter::forViewer()
            $table->bigInteger('max_value')->default(0);                 // money size of the request, tiyn
            $table->boolean('needs_confirmation')->default(false);       // PIN before «Разрешить» (TZ 03 §6.3 p.3, §10)
            $table->string('reason_code', 32)->nullable();
            $table->text('reason_text')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->jsonb('approved_values')->nullable();                // «Разрешить с изменением»
            $table->jsonb('released_groups')->nullable();
            $table->timestampTz('expires_at');                           // pending request lives 30 min
            $table->timestampTz('valid_until')->nullable();              // approval valid 15 min after the answer
            $table->timestampTz('reminded_at')->nullable();              // repeated push after 3 min
            $table->timestampTz('escalated_at')->nullable();             // visible to admins after 5 min
            $table->foreignId('used_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('closed_at')->nullable();                // cancelled / expired / lapsed moment
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'requested_by', 'status']);
            $table->index('document_id');
            $table->index('subject_document_id');
            $table->index(['organization_id', 'action_type', 'action_key']);
        });
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('pending','approved','rejected','cancelled','expired','used','lapsed'))");
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_subject_check CHECK (num_nonnulls(document_id, subject_document_id, action_type) = 1)');
        DB::statement('CREATE UNIQUE INDEX approval_requests_org_id_unique ON approval_requests (organization_id, id)');
        // the same firm only (CORE.md §11): store and documents of the request
        foreach (['store_id' => 'stores', 'document_id' => 'documents', 'subject_document_id' => 'documents', 'used_document_id' => 'documents'] as $column => $referenced) {
            DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_{$column}_same_org_fk
                FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
