<?php

namespace App\Domain\Ledger\Drafts;

use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Idempotency\Idempotency;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Base of the fluent draft builders (SaleDraft, PurchaseDraft, …): creates a `draft` document + its detail rows
 * in one transaction. save() with an idempotency key that already exists returns the existing document (the form
 * was submitted twice). post($actor) = save() + Poster::post().
 */
abstract class DraftBuilder
{
    protected ?int $storeId = null;

    protected ?CarbonInterface $occurredAt = null;

    protected ?string $idempotencyKey = null;

    protected ?string $comment = null;

    protected ?string $reasonText = null;

    protected ?int $authorId = null;

    protected ?string $manualRate = null;

    protected ?int $approvalRequestId = null;

    abstract protected function type(): DocumentType;

    abstract protected function writeDetails(Document $document): void;

    public function occurredAt(CarbonInterface $at): static
    {
        $this->occurredAt = $at;

        return $this;
    }

    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = Idempotency::assertValid($key);

        return $this;
    }

    public function comment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    /** Reason (backdating, corrections). */
    public function reason(?string $text): static
    {
        $this->reasonText = $text;

        return $this;
    }

    public function author(User|int $user): static
    {
        $this->authorId = $user instanceof User ? $user->id : $user;

        return $this;
    }

    /** Manual document rate — owner only, checked at posting (TZ 02 §3.3). */
    public function manualRate(Rate|string $rate): static
    {
        $this->manualRate = Rate::of($rate)->toString();

        return $this;
    }

    public function approvalRequest(?int $id): static
    {
        $this->approvalRequestId = $id;

        return $this;
    }

    public function save(): Document
    {
        if ($this->idempotencyKey && ($existing = Document::findByIdempotencyKey($this->idempotencyKey))) {
            return $existing;
        }
        try {
            return $this->insertDraft();
        } catch (UniqueConstraintViolationException $e) {
            // a concurrent request with the same key (double tap, retry after a lost response) inserted its draft
            // between our lookup and our insert: the unique index waited for it — return that document (TZ 02 §2.4)
            if ($this->idempotencyKey && ($existing = Document::findByIdempotencyKey($this->idempotencyKey))) {
                return $existing;
            }
            throw $e;
        }
    }

    private function insertDraft(): Document
    {
        return DB::transaction(function () {
            $document = new Document;
            $document->forceFill([
                'type' => $this->type()->value,
                'status' => DocumentStatus::Draft->value,
                'store_id' => $this->storeId,
                'occurred_at' => $this->occurredAt ?? now(),
                'idempotency_key' => $this->idempotencyKey ?? Idempotency::newKey(),
                'comment' => $this->comment,
                'reason_text' => $this->reasonText,
                'exchange_rate' => $this->manualRate,
                'exchange_rate_manual' => $this->manualRate !== null,
                'approval_request_id' => $this->approvalRequestId,
                'created_by' => $this->authorId ?? auth()->id(),
            ])->save();
            $this->writeDetails($document);

            return $document->refresh();
        });
    }

    public function post(User $actor): Document
    {
        $this->authorId ??= $actor->id;

        return app(Poster::class)->post($this->save(), $actor);
    }

    protected static function storeId(Store|int|null $store): ?int
    {
        return $store instanceof Store ? $store->id : $store;
    }

    protected static function kzt(Money $money): int
    {
        if ($money->currency !== Currency::KZT) {
            throw new InvalidArgumentException('Amount must be in KZT.');
        }

        return $money->amount;
    }
}
