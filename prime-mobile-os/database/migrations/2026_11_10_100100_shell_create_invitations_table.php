<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links: staff invitation (72 h) and password reset (24 h) — TZ 03 §14, §7.5; TZ 04 Э1.2, Э25.1.
 * Only the SHA-256 of the token is stored. Opening the link is recorded (TZ 03 §14.3: «где её открыли»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS memberships_org_id_unique ON memberships (organization_id, id)');

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->default('invite');       // invite | password_reset
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('opened_at')->nullable();
            $table->string('opened_ip', 45)->nullable();
            $table->string('opened_device', 80)->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'membership_id']);
        });
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_kind_check CHECK (kind IN ('invite','password_reset'))");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_membership_same_org_fk FOREIGN KEY (organization_id, membership_id) REFERENCES memberships (organization_id, id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        DB::statement('DROP INDEX IF EXISTS memberships_org_id_unique');
    }
};
