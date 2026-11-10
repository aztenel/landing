<?php

namespace App\Domain\Storefront\Listeners;

use App\Domain\Storefront\Services\Leads;
use App\Domain\Storefront\Services\ListingBuilder;
use App\Support\Outbox\OutboxMessage;

/**
 * Outbox → storefront (TZ 05 §8.1). Runs inside the dispatcher (organization context of the row is set). Rebuilds the
 * cards WHOLE, so a repeated delivery changes nothing (§8.2 «идемпотентность»).
 */
final class SyncStorefront
{
    /** Documents that move accessories: their availability on the site may change. */
    private const ACCESSORY_DOCS = ['purchase', 'sale', 'customer_return', 'supplier_return', 'transfer_out', 'transfer_in', 'write_off', 'revision', 'opening_balance'];

    public function __construct(private readonly ListingBuilder $builder) {}

    public function handle(OutboxMessage $m): void
    {
        $p = $m->payload;
        match ($m->topic) {
            'document.posted', 'document.reversed' => $this->document($m),
            'unit.changed' => $this->builder->units([(int) ($p['stock_unit_id'] ?? 0)]),
            'site.changed' => $this->site($p),
            default => null,
        };
    }

    private function document(OutboxMessage $m): void
    {
        $p = $m->payload;
        $this->builder->units((array) ($p['stock_unit_ids'] ?? []));
        if (in_array($p['type'] ?? '', self::ACCESSORY_DOCS, true)) {
            $this->builder->accessories();
            ListingBuilder::touch();
        }
        if ($m->topic === 'document.posted' && ($p['type'] ?? '') === 'sale') {
            app(Leads::class)->onSale((int) $p['document_id'], (array) ($p['stock_unit_ids'] ?? []));
        }
    }

    private function site(array $p): void
    {
        if (! empty($p['all'])) {
            $this->builder->all();

            return;
        }
        if (! empty($p['accessories'])) {
            $this->builder->stores();
            $this->builder->accessories();
            ListingBuilder::touch();
        }
        $this->builder->units((array) ($p['stock_unit_ids'] ?? []));
    }
}
