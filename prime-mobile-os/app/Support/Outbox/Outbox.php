<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Write side of the transactional outbox (audit №5). Call it INSIDE the transaction that makes the change:
 *
 *   Outbox::record('unit.changed', ['stock_unit_id' => $unit->id, 'from' => 'in_stock', 'to' => 'reserved'], 'stock_unit', $unit->id);
 *
 * Topics written by the core: document.posted, document.reversed (payload: document id, type, number, stock_unit_ids)
 * and unit.changed (cart, orders, «готов к продаже» …). Delivery — OutboxDispatcher (command prime:outbox).
 */
final class Outbox
{
    public static function record(string $topic, array $payload, ?string $subjectType = null, ?int $subjectId = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox::record() must run inside the transaction of the change it announces.');
        }
        DB::table('outbox_events')->insert([
            'organization_id' => CurrentOrganization::instance()->require()->id,
            'topic' => $topic,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
