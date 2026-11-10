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
use App\Domain\Tenancy\Access\Limit;
use Illuminate\Support\Collection;

/**
 * «Бонусы вручную» БНС (TZ 02 §6.2 №42, TZ 03 право 29, L17). Every line has a reason. + is a new source available at
 * once, it lives the firm's lifetime (bonus.lifetime_days); − takes the customer's bonuses FIFO by expiry. The limit L17
 * counts the sum given (admin 10 000 ₸ by default; owner — any).
 */
class BonusManualHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly BonusLedger $bonuses) {}

    public function type(): DocumentType
    {
        return DocumentType::BonusManual;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return ShiftRequirement::Never;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $lines = $this->lines($document);
        if ($lines->isEmpty()) {
            throw PostingException::because('no_lines', 'Добавьте клиента и сумму бонусов');
        }
        if ($lines->contains(fn ($l) => $l->kind !== 'manual' || trim((string) $l->comment) === '')) {
            throw PostingException::because('reason_required', 'Напишите причину для каждой строки');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        foreach ($this->lines($document)->pluck('customer_id')->unique() as $id) {
            $plan->bonus((int) $id);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $lines = $this->lines($document);
        $given = (int) $lines->where('amount', '>', 0)->sum('amount');
        $ctx->authorize('bonus.manual', $given > 0 ? Limit::BonusManual : null, $given > 0 ? $given * 100 : null,
            message: 'Бонусы вручную — админ (по лимиту) или владелец');

        $lifetime = (int) $ctx->settings()->get('bonus.lifetime_days');
        foreach ($lines as $line) {
            if ($line->amount > 0) {
                $this->bonuses->accrue($ctx, (int) $line->customer_id, (int) $line->amount, $ctx->now,
                    $lifetime > 0 ? $ctx->now->addDays($lifetime) : null, BonusKind::Manual, comment: $line->comment);
            } else {
                $this->bonuses->consume($ctx, (int) $line->customer_id, -(int) $line->amount, BonusKind::Manual, includePending: true);
            }
        }
    }

    /** @return Collection<int, BonusManualLine> */
    private function lines(Document $document): Collection
    {
        return BonusManualLine::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
