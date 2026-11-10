<?php

namespace App\Domain\Loyalty\Services;

use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Loyalty\Models\BonusManualLine;
use App\Support\Idempotency\Idempotency;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Nightly «Сгорание бонусов» БСГ of the current organization: one document per firm per night (audit №54), a line per
 * customer with expired remainders. Returns the posted document or null when nothing expired.
 */
class BonusExpiry
{
    public function __construct(private readonly Poster $poster) {}

    public function run(): ?Document
    {
        $orgId = CurrentOrganization::instance()->requireId();
        $rows = DB::select(
            'SELECT customer_id, SUM(remaining) AS remaining FROM (
                SELECT e.customer_id, e.amount - COALESCE(SUM(a.amount), 0) AS remaining
                  FROM bonus_entries e LEFT JOIN bonus_allocations a ON a.accrual_entry_id = e.id
                 WHERE e.organization_id = ? AND e.is_source AND e.expires_at IS NOT NULL AND e.expires_at <= now()
                 GROUP BY e.id) s
              WHERE remaining > 0 GROUP BY customer_id ORDER BY customer_id',
            [$orgId],
        );
        if ($rows === []) {
            return null;
        }

        // one document per firm per night: a second run the same night returns the first one
        $key = Idempotency::derived('bonus-expire', $orgId, now(CurrentOrganization::instance()->require()->timezone)->format('Y-m-d'));
        if ($existing = Document::findByIdempotencyKey($key)) {
            return $existing->status === DocumentStatus::Posted ? $existing : $this->poster->postAsSystem($existing);
        }

        $document = DB::transaction(function () use ($rows, $orgId, $key) {
            $doc = new Document;
            $doc->forceFill([
                'organization_id' => $orgId, 'type' => DocumentType::BonusExpire->value, 'status' => DocumentStatus::Draft->value,
                'occurred_at' => now(), 'idempotency_key' => $key, 'meta' => ['system' => true], 'comment' => __('Сгорание бонусов'),
            ])->save();
            foreach ($rows as $r) {
                BonusManualLine::query()->create([
                    'document_id' => $doc->id, 'customer_id' => (int) $r->customer_id, 'kind' => 'expire', 'amount' => -(int) $r->remaining,
                ]);
            }

            return $doc;
        });

        return $this->poster->postAsSystem($document);
    }
}
