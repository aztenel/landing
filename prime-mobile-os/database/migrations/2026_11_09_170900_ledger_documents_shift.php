<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Audit №18: a posted document remembers the shift of its store (the shift report is built by this link). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents ADD COLUMN shift_id bigint NULL REFERENCES shifts (id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX documents_shift_id_index ON documents (shift_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP COLUMN IF EXISTS shift_id');
    }
};
