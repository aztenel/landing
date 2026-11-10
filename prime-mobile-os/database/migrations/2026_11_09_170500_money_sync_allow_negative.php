<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Audit №50: changing «счёт может уходить в минус» on an account takes effect at once (the balance copy follows). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_sync_allow_negative() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.allow_negative IS DISTINCT FROM OLD.allow_negative THEN
        UPDATE account_balances SET allow_negative = NEW.allow_negative, updated_at = now() WHERE account_id = NEW.id;
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::unprepared('CREATE TRIGGER money_accounts_sync_allow_negative AFTER UPDATE OF allow_negative ON money_accounts FOR EACH ROW EXECUTE FUNCTION pm_sync_allow_negative()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS money_accounts_sync_allow_negative ON money_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS pm_sync_allow_negative()');
    }
};
