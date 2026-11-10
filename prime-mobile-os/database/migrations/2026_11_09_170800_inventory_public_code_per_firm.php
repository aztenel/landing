<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit №43: the public code of a phone (price tags, site) — «<prefix>-<number>» with a counter of each firm, starting
 * at 10001; the prefix is the firm setting storefront.code_prefix (default «PM»). Unique within the firm. The code is
 * issued by the database on insert, so no module can forget it or issue a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE public_code_counters (
            organization_id bigint PRIMARY KEY REFERENCES organizations (id) ON DELETE RESTRICT,
            last_number bigint NOT NULL DEFAULT 10000
        )');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_stock_unit_public_code() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    next_number bigint;
    prefix text;
BEGIN
    IF NEW.public_code IS NOT NULL THEN
        RETURN NEW;
    END IF;
    INSERT INTO public_code_counters (organization_id) VALUES (NEW.organization_id) ON CONFLICT DO NOTHING;
    UPDATE public_code_counters SET last_number = last_number + 1 WHERE organization_id = NEW.organization_id
        RETURNING last_number INTO next_number;
    SELECT COALESCE(NULLIF(settings #>> '{storefront,code_prefix}', ''), 'PM') INTO prefix FROM organizations WHERE id = NEW.organization_id;
    NEW.public_code := prefix || '-' || next_number;
    RETURN NEW;
END
$$;
SQL);
        DB::unprepared('CREATE TRIGGER stock_units_public_code BEFORE INSERT ON stock_units FOR EACH ROW EXECUTE FUNCTION pm_stock_unit_public_code()');
        DB::statement('ALTER TABLE stock_units ALTER COLUMN public_code SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX stock_units_org_public_code_unique ON stock_units (organization_id, public_code)');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_units_public_code ON stock_units');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_stock_unit_public_code()');
        DB::statement('DROP INDEX IF EXISTS stock_units_org_public_code_unique');
        DB::statement('DROP TABLE IF EXISTS public_code_counters');
    }
};
