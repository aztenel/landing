<?php

namespace App\Domain\Storefront\Support;

use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Announce a change the site must pick up (TZ 05 §8.1): a row in the outbox, in the transaction of the change. The
 * documents of the ledger announce themselves (document.posted / .reversed); this is for the rest — prices, photos,
 * switches, catalogue, stores, госбаза.
 */
final class SiteChange
{
    public static function units(array $ids, string $why): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            DB::transaction(fn () => Outbox::record('site.changed', ['stock_unit_ids' => $ids, 'why' => $why]));
        }
    }

    /** Catalogue, stores, settings: every card may change (§8.2 «массовые изменения»). */
    public static function all(string $why): void
    {
        DB::transaction(fn () => Outbox::record('site.changed', ['all' => true, 'why' => $why]));
    }

    public static function accessories(string $why): void
    {
        DB::transaction(fn () => Outbox::record('site.changed', ['accessories' => true, 'why' => $why]));
    }
}
