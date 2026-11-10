<?php

namespace App\Domain\Loyalty\Posting;

use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\ShiftRequirement;
use App\Domain\Loyalty\Models\BonusManualLine;
use App\Domain\Ledger\Exceptions\ReversalBlocked;

/**
 * «Сгорание бонусов» БСГ (audit №54): the nightly system document. Every line = what is left of the customer's sources
 * whose expires_at has passed; the entry «expire» consumes exactly those sources. Expired bonuses are already not
 * available (the balance ignores them) — the document makes the fact visible in the customer's history and reports.
 * Posted only by Poster::postAsSystem(); not reversible (a gift by «Бонусы вручную» gives them back).
 */
class BonusExpireHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly BonusLedger $bonuses) {}

    public function type(): DocumentType
    {
        return DocumentType::BonusExpire;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return ShiftRequirement::Never;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        if (! ($document->meta['system'] ?? false)) {
            throw PostingException::because('system_only', 'Сгорание бонусов проводит только система');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        foreach (BonusManualLine::query()->where('document_id', $document->id)->pluck('customer_id')->unique() as $id) {
            $plan->bonus((int) $id);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        foreach (BonusManualLine::query()->where('document_id', $document->id)->orderBy('id')->get() as $line) {
            $expired = $this->bonuses->sources((int) $line->customer_id)
                ->filter(fn ($s) => $s->expires_at !== null && $s->expires_at <= $ctx->now);
            if ($line->kind !== 'expire' || (int) $expired->sum('remaining') !== -(int) $line->amount) {
                throw PostingException::because('expire_changed', 'Сгорающие бонусы изменились — повторите ночью');
            }
            $this->bonuses->consume($ctx, (int) $line->customer_id, -(int) $line->amount, BonusKind::Expire,
                $expired->pluck('id')->all(), includePending: true);
        }
    }

    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        throw ReversalBlocked::because('system_document', 'Сгорание бонусов не сторнируется — подарите бонусы вручную');
    }
}
