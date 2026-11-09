<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\AccessoryCost;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\DocumentCounter;
use Illuminate\Support\Facades\DB;

/**
 * Takes row locks for a posting in the global order (ENGINEERING.md rule 10, TZ 02 §12.3), each group by id:
 *   1 stock_units → 2 accessory_costs → 3 accessory_balances → 4 account_balances → 5 counterparty_balances
 *   → 6 customer_bonus_balances → 7 document_counters → 8 hash-chain advisory lock of the organization.
 * Missing balance rows are created first (INSERT … ON CONFLICT DO NOTHING) and then locked.
 * Must run inside a transaction.
 */
class LockManager
{
    public function acquire(LockPlan $plan, int $organizationId, string $series, int $year): LockSet
    {
        $set = new LockSet;
        $now = now();

        // 1. stock units
        if ($plan->units) {
            $ids = array_values($plan->units);
            sort($ids);
            $units = StockUnit::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($units->count() !== count($ids)) {
                throw PostingException::because('unit_not_found', 'Телефон не найден в этой фирме');
            }
            $units->each(fn (StockUnit $u) => $set->putUnit($u));
        }

        // 2. accessory average costs
        if ($plan->accessories) {
            $ids = array_values($plan->accessories);
            sort($ids);
            DB::table('accessory_costs')->insertOrIgnore(array_map(fn (int $id) => [
                'organization_id' => $organizationId, 'accessory_id' => $id, 'updated_at' => $now,
            ], $ids));
            AccessoryCost::query()->whereIn('accessory_id', $ids)->orderBy('id')->lockForUpdate()->get()
                ->each(fn (AccessoryCost $c) => $set->putAccessoryCost($c));
        }

        // 3. accessory balances per place
        if ($plan->accessoryPlaces) {
            // missing rows are inserted in a fixed (key) order too: two postings creating the same rows never deadlock
            $places = $plan->accessoryPlaces;
            ksort($places);
            $places = array_values($places);
            DB::table('accessory_balances')->insertOrIgnore(array_map(fn (array $p) => [
                'organization_id' => $organizationId,
                'location_type' => $p[0]->type->value,
                'location_id' => $p[0]->id,
                'accessory_id' => $p[1],
                'updated_at' => $now,
            ], $places));
            AccessoryBalance::query()
                ->where(function ($q) use ($places) {
                    foreach ($places as [$location, $accessoryId]) {
                        $q->orWhere(fn ($w) => $w->where('location_type', $location->type->value)
                            ->where('location_id', $location->id)->where('accessory_id', $accessoryId));
                    }
                })
                ->orderBy('id')->lockForUpdate()->get()
                ->each(fn (AccessoryBalance $b) => $set->putAccessoryBalance($b));
        }

        // 4. account balances
        if ($plan->accounts) {
            $ids = array_values($plan->accounts);
            sort($ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::insert(
                "INSERT INTO account_balances (organization_id, account_id, currency, allow_negative, updated_at)
                 SELECT organization_id, id, currency, allow_negative, ? FROM money_accounts
                 WHERE organization_id = ? AND id IN ($placeholders)
                 ON CONFLICT (account_id) DO NOTHING",
                [$now, $organizationId, ...$ids],
            );
            $balances = AccountBalance::query()->whereIn('account_id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($balances->count() !== count($ids)) {
                throw PostingException::because('account_not_found', 'Счёт не найден в этой фирме');
            }
            $balances->each(fn (AccountBalance $b) => $set->putAccount($b));
        }

        // 5. counterparty balances
        if ($plan->counterparties) {
            $keys = $plan->counterparties;
            ksort($keys);
            $keys = array_values($keys);
            $this->assertCounterpartiesBelong($organizationId, array_map(fn (array $k) => [$k[0], $k[1]], $keys));
            DB::table('counterparty_balances')->insertOrIgnore(array_map(fn (array $k) => [
                'organization_id' => $organizationId,
                'counterparty_type' => $k[0]->value,
                'counterparty_id' => $k[1],
                'currency' => $k[2]->value,
                'updated_at' => $now,
            ], $keys));
            CounterpartyBalance::query()
                ->where(function ($q) use ($keys) {
                    foreach ($keys as [$type, $id, $currency]) {
                        $q->orWhere(fn ($w) => $w->where('counterparty_type', $type->value)
                            ->where('counterparty_id', $id)->where('currency', $currency->value));
                    }
                })
                ->orderBy('id')->lockForUpdate()->get()
                ->each(fn (CounterpartyBalance $b) => $set->putCounterparty($b));
        }

        // 6. customer bonus balances
        if ($plan->bonusCustomers) {
            $ids = array_values($plan->bonusCustomers);
            sort($ids);
            $this->assertCounterpartiesBelong($organizationId, array_map(fn (int $id) => [CounterpartyType::Customer, $id], $ids));
            DB::table('customer_bonus_balances')->insertOrIgnore(array_map(fn (int $id) => [
                'organization_id' => $organizationId, 'customer_id' => $id, 'updated_at' => $now,
            ], $ids));
            CustomerBonusBalance::query()->whereIn('customer_id', $ids)->orderBy('id')->lockForUpdate()->get()
                ->each(fn (CustomerBonusBalance $b) => $set->putBonus($b));
        }

        // 7. number counter of the series and year
        DB::table('document_counters')->insertOrIgnore([
            'organization_id' => $organizationId, 'series' => $series, 'year' => $year,
            'last_number' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $set->setCounter(DocumentCounter::query()->where('series', $series)->where('year', $year)->lockForUpdate()->firstOrFail());

        // 8. hash chain of the journals of this organization (also taken by the journal trigger)
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [(int) config('prime.chain_lock_key'), $organizationId]);

        return $set;
    }

    /**
     * Counterparty ids come from draft rows (i.e. from a request): an id of another organization's customer or
     * supplier must never get a balance row / journal entry here (TZ 03 §18 p.4). Units and accounts are checked
     * by their scoped lock queries above; counterparties are polymorphic, so they are checked explicitly.
     *
     * @param  list<array{0: CounterpartyType, 1: int}>  $pairs
     */
    private function assertCounterpartiesBelong(int $organizationId, array $pairs): void
    {
        $byType = [];
        foreach ($pairs as [$type, $id]) {
            $byType[$type->value][$id] = $id;
        }
        foreach ($byType as $type => $ids) {
            $type = CounterpartyType::from($type);
            $found = DB::table($type === CounterpartyType::Customer ? 'customers' : 'suppliers')
                ->where('organization_id', $organizationId)->whereIn('id', array_values($ids))->count();
            if ($found !== count($ids)) {
                throw $type === CounterpartyType::Customer
                    ? PostingException::because('customer_not_found', 'Клиент не найден в этой фирме')
                    : PostingException::because('supplier_not_found', 'Поставщик не найден в этой фирме');
            }
        }
    }
}
