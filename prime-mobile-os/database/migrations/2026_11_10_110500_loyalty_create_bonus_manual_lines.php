<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Бонусы вручную» БНС (TZ 02 §6.2 №42) and the nightly expiry of bonuses (№43, kind expire) — both are
 * bonus_manual documents; lines are customers × signed whole bonuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_manual_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('kind', 8)->default('manual');   // manual | expire
            $table->bigInteger('amount');                   // whole bonuses: + gift / compensation, − write-off / expiry
            $table->string('comment')->nullable();
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement("ALTER TABLE bonus_manual_lines ADD CONSTRAINT bonus_manual_lines_check CHECK (amount <> 0 AND kind IN ('manual','expire') AND (kind = 'manual' OR amount < 0))");
        DB::statement('ALTER TABLE bonus_manual_lines ADD CONSTRAINT bonus_manual_lines_customer_same_org_fk
            FOREIGN KEY (organization_id, customer_id) REFERENCES customers (organization_id, id) ON DELETE RESTRICT');
        DB::unprepared('CREATE TRIGGER bonus_manual_lines_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON bonus_manual_lines FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_manual_lines');
    }
};
