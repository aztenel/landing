<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\AccessoryCost;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\DocumentCounter;
use App\Support\Money\Currency;
use LogicException;

/**
 * Rows locked FOR UPDATE in the current posting transaction. Ledgers read and write balance copies ONLY through
 * this set; asking for a row that was not planned is a developer error (LogicException) — it would break the
 * global lock order.
 */
final class LockSet
{
    /** @var array<int, StockUnit> */
    private array $units = [];

    /** @var array<int, AccessoryCost> */
    private array $accessoryCosts = [];

    /** @var array<string, AccessoryBalance> */
    private array $accessoryBalances = [];

    /** @var array<int, AccountBalance> */
    private array $accounts = [];

    /** @var array<string, CounterpartyBalance> */
    private array $counterparties = [];

    /** @var array<int, CustomerBonusBalance> */
    private array $bonus = [];

    private ?DocumentCounter $counter = null;

    public function putUnit(StockUnit $unit): void
    {
        $this->units[$unit->id] = $unit;
    }

    public function hasUnit(int $id): bool
    {
        return isset($this->units[$id]);
    }

    public function unit(int $id): StockUnit
    {
        return $this->units[$id] ?? throw new LogicException("Stock unit {$id} is not locked: add it to the LockPlan.");
    }

    /** @return array<int, StockUnit> */
    public function units(): array
    {
        return $this->units;
    }

    public function putAccessoryCost(AccessoryCost $cost): void
    {
        $this->accessoryCosts[(int) $cost->accessory_id] = $cost;
    }

    public function accessoryCost(int $accessoryId): AccessoryCost
    {
        return $this->accessoryCosts[$accessoryId] ?? throw new LogicException("Accessory cost {$accessoryId} is not locked: add it to the LockPlan.");
    }

    public function putAccessoryBalance(AccessoryBalance $balance): void
    {
        $key = $balance->location_type.':'.$balance->location_id.':'.$balance->accessory_id;
        $this->accessoryBalances[$key] = $balance;
    }

    public function accessoryBalance(Location $location, int $accessoryId): AccessoryBalance
    {
        return $this->accessoryBalances[$location->key().':'.$accessoryId]
            ?? throw new LogicException("Accessory balance {$location->key()}:{$accessoryId} is not locked: add it to the LockPlan.");
    }

    public function putAccount(AccountBalance $balance): void
    {
        $this->accounts[(int) $balance->account_id] = $balance;
    }

    public function account(int $accountId): AccountBalance
    {
        return $this->accounts[$accountId] ?? throw new LogicException("Account balance {$accountId} is not locked: add it to the LockPlan.");
    }

    public function putCounterparty(CounterpartyBalance $balance): void
    {
        $key = LockPlan::counterpartyKey(CounterpartyType::from($balance->counterparty_type), (int) $balance->counterparty_id, Currency::from($balance->currency));
        $this->counterparties[$key] = $balance;
    }

    public function counterparty(CounterpartyType $type, int $id, Currency $currency): CounterpartyBalance
    {
        return $this->counterparties[LockPlan::counterpartyKey($type, $id, $currency)]
            ?? throw new LogicException("Counterparty balance {$type->value}:{$id}:{$currency->value} is not locked: add it to the LockPlan.");
    }

    public function putBonus(CustomerBonusBalance $balance): void
    {
        $this->bonus[(int) $balance->customer_id] = $balance;
    }

    public function bonus(int $customerId): CustomerBonusBalance
    {
        return $this->bonus[$customerId] ?? throw new LogicException("Bonus balance of customer {$customerId} is not locked: add it to the LockPlan.");
    }

    public function setCounter(DocumentCounter $counter): void
    {
        $this->counter = $counter;
    }

    public function counter(): DocumentCounter
    {
        return $this->counter ?? throw new LogicException('Document counter is not locked.');
    }
}
