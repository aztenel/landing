<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integrity review of the core (TZ 02 §2.5, §11.1):
 *  - documents: status «reversed» ⇔ reversed_by_document_id is set (no half-reversed document);
 *  - pm_posted_detail_guard(): an UPDATE may not move a detail row INTO a posted document either
 *    (before, only the old document was checked: UPDATE sale_lines SET document_id = <posted> WHERE … passed);
 *  - pm_journal_chain(): the hashed payload is jsonb_strip_nulls(row − hash), so adding a NULLable column to a journal
 *    later (a module migration) does not «break» the chain of all existing rows. ChainVerifier hashes the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_reversed_link_check CHECK ((status = 'reversed') = (reversed_by_document_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_posted_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    doc_status text;
    new_status text;
    doc_id     bigint;
    mutable    text[];
BEGIN
    IF TG_OP = 'INSERT' THEN doc_id := NEW.document_id; ELSE doc_id := OLD.document_id; END IF;
    SELECT status INTO doc_status FROM documents WHERE id = doc_id;

    -- a row may not be moved into a non-draft document
    IF TG_OP = 'UPDATE' AND NEW.document_id IS DISTINCT FROM OLD.document_id THEN
        SELECT status INTO new_status FROM documents WHERE id = NEW.document_id;
        IF new_status IS NOT NULL AND new_status <> 'draft' THEN
            RAISE EXCEPTION 'Строки проведённого документа (%) менять нельзя: перенос строки в проведённый документ', TG_TABLE_NAME
                USING ERRCODE = 'P0001';
        END IF;
    END IF;

    IF doc_status IS NULL OR doc_status = 'draft' THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Строки проведённого документа (%) менять нельзя: % запрещён', TG_TABLE_NAME, TG_OP USING ERRCODE = 'P0001';
    END IF;

    mutable := COALESCE(TG_ARGV::text[], ARRAY[]::text[]) || ARRAY['updated_at'];
    IF (to_jsonb(NEW) - mutable) IS DISTINCT FROM (to_jsonb(OLD) - mutable) THEN
        RAISE EXCEPTION 'Строки проведённого документа (%) менять нельзя', TG_TABLE_NAME USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END
$$;
SQL);

        $lockKey = (int) config('prime.chain_lock_key');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION pm_journal_chain() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE
    prev_seq  bigint;
    prev_hash text;
BEGIN
    -- serialize appends per organization (the Poster takes the same lock last, after row locks)
    PERFORM pg_advisory_xact_lock({$lockKey}, NEW.organization_id::int);
    EXECUTE format('SELECT chain_seq, hash FROM %I WHERE organization_id = \$1 ORDER BY chain_seq DESC LIMIT 1', TG_TABLE_NAME)
        INTO prev_seq, prev_hash USING NEW.organization_id;
    NEW.chain_seq := COALESCE(prev_seq, 0) + 1;
    NEW.hash := NULL;
    NEW.hash := encode(sha256(convert_to(COALESCE(prev_hash, '') || (jsonb_strip_nulls(to_jsonb(NEW) - 'hash')::text), 'UTF8')), 'hex');
    RETURN NEW;
END
\$\$;
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_reversed_link_check');
        // the previous function bodies are re-created by 2026_11_09_160800_ledger_create_guard_triggers on a fresh migrate
    }
};
