<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\LocationType;

/**
 * Place of goods: a store or «в пути» of a transfer document (TZ 02 §11.2 location_type/location_id).
 */
final class Location
{
    public function __construct(public readonly LocationType $type, public readonly int $id) {}

    public static function store(int $storeId): self
    {
        return new self(LocationType::Store, $storeId);
    }

    public static function transit(int $transferDocumentId): self
    {
        return new self(LocationType::Transit, $transferDocumentId);
    }

    public static function fromColumns(?string $type, ?int $id): ?self
    {
        return $type === null || $id === null ? null : new self(LocationType::from($type), (int) $id);
    }

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->type === $this->type && $other->id === $this->id;
    }

    public function isStore(): bool
    {
        return $this->type === LocationType::Store;
    }
}
