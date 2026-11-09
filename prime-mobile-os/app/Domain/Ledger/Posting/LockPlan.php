<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Location;
use App\Support\Money\Currency;

/**
 * What a handler needs locked. LockManager takes the locks in the fixed order of ENGINEERING.md rule 10 /
 * TZ 02 §12.3: stock_units → accessory_costs → accessory_balances → account_balances → counterparty_balances
 * → customer_bonus_balances → document_counters (→ hash-chain advisory lock), each group by ascending id.
 */
final class LockPlan
{
    /** @var array<int, int> */
    public array $units = [];

    /** @var array<int, int> */
    public array $accessories = [];

    /** @var array<string, array{0: Location, 1: int}> */
    public array $accessoryPlaces = [];

    /** @var array<int, int> */
    public array $accounts = [];

    /** @var array<string, array{0: CounterpartyType, 1: int, 2: Currency}> */
    public array $counterparties = [];

    /** @var array<int, int> */
    public array $bonusCustomers = [];

    public function unit(?int $stockUnitId): self
    {
        if ($stockUnitId) {
            $this->units[$stockUnitId] = $stockUnitId;
        }

        return $this;
    }

    /** Locks the organization average cost row and the balance row of the place. */
    public function accessory(int $accessoryId, Location $location): self
    {
        $this->accessories[$accessoryId] = $accessoryId;
        $this->accessoryPlaces[$location->key().':'.$accessoryId] = [$location, $accessoryId];

        return $this;
    }

    public function account(?int $accountId): self
    {
        if ($accountId) {
            $this->accounts[$accountId] = $accountId;
        }

        return $this;
    }

    public function counterparty(CounterpartyType $type, int $id, Currency $currency): self
    {
        $this->counterparties[self::counterpartyKey($type, $id, $currency)] = [$type, $id, $currency];

        return $this;
    }

    public function bonus(?int $customerId): self
    {
        if ($customerId) {
            $this->bonusCustomers[$customerId] = $customerId;
        }

        return $this;
    }

    public function merge(self $other): self
    {
        $this->units += $other->units;
        $this->accessories += $other->accessories;
        $this->accessoryPlaces += $other->accessoryPlaces;
        $this->accounts += $other->accounts;
        $this->counterparties += $other->counterparties;
        $this->bonusCustomers += $other->bonusCustomers;

        return $this;
    }

    public static function counterpartyKey(CounterpartyType $type, int $id, Currency $currency): string
    {
        return $type->value.':'.$id.':'.$currency->value;
    }
}
