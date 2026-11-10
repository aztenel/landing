<?php

namespace App\Domain\Reports\Services;

use App\Models\User;
use App\Support\Ui\WorkingStore;

/**
 * Report filters (TZ 06 §1.2) together with what the user may see (§1.3): an admin is limited to his stores whatever
 * the address says. Built from the query string, so a link opens the same report (with the rights of who opens it).
 */
final class Scope
{
    /** @param list<int> $storeIds */
    public function __construct(
        public readonly array $storeIds,
        public readonly ?string $direction = null,     // phones | accessories
        public readonly ?int $sellerId = null,
        public readonly ?string $condition = null,     // new | used
        public readonly ?string $typeCode = null,
        public readonly ?int $modelId = null,
        public readonly ?string $source = null,
    ) {}

    public static function for(User $user, array $q = []): self
    {
        $allowed = WorkingStore::available($user)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $store = (int) ($q['store'] ?? 0);

        return new self(
            $store && in_array($store, $allowed, true) ? [$store] : $allowed,
            in_array($q['dir'] ?? null, ['phones', 'accessories'], true) ? $q['dir'] : null,
            ! empty($q['seller']) ? (int) $q['seller'] : null,
            in_array($q['cond'] ?? null, ['new', 'used'], true) ? $q['cond'] : null,
            ! empty($q['type']) ? (string) $q['type'] : null,
            ! empty($q['model']) ? (int) $q['model'] : null,
            ! empty($q['source']) ? (string) $q['source'] : null,
        );
    }

    public static function all(array $storeIds): self
    {
        return new self($storeIds);
    }

    /** Commissions, expenses and «прочее» belong to a check or a store, not to a line: only store filters apply to them. */
    public function lineFiltered(): bool
    {
        return $this->direction || $this->sellerId || $this->condition || $this->typeCode || $this->modelId || $this->source;
    }
}
