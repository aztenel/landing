<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level guarantees (TZ 02 §2.5, §9.2):
 *  - journals are append-only (UPDATE/DELETE/TRUNCATE raise);
 *  - journals carry a per-organization hash chain (chain_seq + SHA-256);
 *  - a posted document is immutable except status→reversed, reversed_by_document_id, comment, fiscal_receipt_no;
 *  - detail rows of a non-draft document cannot be inserted/changed/deleted (whitelisted columns excepted);
 *  - a document cannot become posted with a business date inside a closed period.
 */
return new class extends Migration
{
    /** Advisory lock key serializing the hash chain of one organization. Keep in sync with config('prime.chain_lock_key'). */
    public const CHAIN_LOCK_KEY = 7700101;

    public const APPEND_ONLY = ['inventory_movements', 'money_entries', 'debt_entries', 'bonus_entries', 'bonus_allocations', 'exchange_rates', 'imei_checks', 'price_changes'];

    public const CHAINED = ['inventory_movements', 'money_entries', 'debt_entries', 'bonus_entries'];

    /** table => columns that may still change after the document is posted */
    public const DETAILS = [
        'sales' => [],
        'sale_lines' => ['is_active', 'returned_qty'],
        'sale_payments' => [],
        'customer_returns' => [],
        'customer_return_lines' => [],
        'customer_return_payments' => [],
        'purchases' => [],
        'purchase_lines' => [],
        'purchase_payments' => [],
        'expenses' => [],
        'expense_payments' => [],
        'debt_payments' => [],
        'fx_conversions' => [],
        'opening_balance_lines' => [],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Журнал % только для добавления: % запрещён. Исправление — сторно.', TG_TABLE_NAME, TG_OP
        USING ERRCODE = 'P0001';
END
$$;
SQL);

        $lockKey = self::CHAIN_LOCK_KEY;
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
    NEW.hash := encode(sha256(convert_to(COALESCE(prev_hash, '') || ((to_jsonb(NEW) - 'hash')::text), 'UTF8')), 'hex');
    RETURN NEW;
END
\$\$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    mutable text[] := ARRAY['status', 'reversed_by_document_id', 'comment', 'fiscal_receipt_no', 'updated_at'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'Проведённый документ % удалить нельзя', OLD.number USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'draft' THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'posted' AND NEW.status NOT IN ('posted', 'reversed') THEN
        RAISE EXCEPTION 'Документ % нельзя вернуть в статус %', OLD.number, NEW.status USING ERRCODE = 'P0001';
    END IF;
    IF OLD.status = 'reversed' AND (NEW.status <> 'reversed' OR NEW.reversed_by_document_id IS DISTINCT FROM OLD.reversed_by_document_id) THEN
        RAISE EXCEPTION 'Сторнированный документ % менять нельзя', OLD.number USING ERRCODE = 'P0001';
    END IF;
    IF (to_jsonb(NEW) - mutable) IS DISTINCT FROM (to_jsonb(OLD) - mutable) THEN
        RAISE EXCEPTION 'Проведённый документ % менять нельзя (только комментарий и номер фискального чека)', OLD.number
            USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END
$$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_documents_period_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    closed date;
    tz     text;
BEGIN
    IF NEW.status = 'posted' AND (TG_OP = 'INSERT' OR OLD.status = 'draft') THEN
        SELECT closed_until, timezone INTO closed, tz FROM organizations WHERE id = NEW.organization_id;
        IF closed IS NOT NULL AND (NEW.occurred_at AT TIME ZONE tz)::date <= closed THEN
            RAISE EXCEPTION 'Период закрыт по % включительно: документ датой % провести нельзя',
                to_char(closed, 'DD.MM.YYYY'), to_char((NEW.occurred_at AT TIME ZONE tz)::date, 'DD.MM.YYYY')
                USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END
$$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_posted_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    doc_status text;
    doc_id     bigint;
    mutable    text[];
BEGIN
    IF TG_OP = 'INSERT' THEN doc_id := NEW.document_id; ELSE doc_id := OLD.document_id; END IF;
    SELECT status INTO doc_status FROM documents WHERE id = doc_id;
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

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION pm_append_only()");
            DB::unprepared("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION pm_append_only()");
        }

        foreach (self::CHAINED as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_chain BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION pm_journal_chain()");
        }

        DB::unprepared('CREATE TRIGGER documents_guard BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION pm_documents_guard()');
        DB::unprepared('CREATE TRIGGER documents_period_guard BEFORE INSERT OR UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION pm_documents_period_guard()');

        foreach (self::DETAILS as $table => $mutable) {
            $args = implode(', ', array_map(fn ($c) => "'{$c}'", $mutable));
            DB::unprepared("CREATE TRIGGER {$table}_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard({$args})");
        }
    }

    public function down(): void
    {
        foreach (self::DETAILS as $table => $_) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_posted_guard ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS documents_period_guard ON documents');
        DB::unprepared('DROP TRIGGER IF EXISTS documents_guard ON documents');
        foreach (self::CHAINED as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_chain ON {$table}");
        }
        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS pm_posted_detail_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_documents_period_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_documents_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_journal_chain()');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_append_only()');
    }
};
