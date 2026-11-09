<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\AccessDecision;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\OrganizationSettings;
use App\Models\User;
use App\Support\Money\Rate;
use Carbon\CarbonImmutable;

/**
 * Everything a handler and the ledgers need during ONE posting (or reversal) transaction.
 */
final class PostingContext
{
    public LockSet $locks;

    /** An approved request (ApprovalGate) authorized something in this posting — see Poster (documents.approval_request_id). */
    private bool $approvalUsed = false;

    private ?Membership $membership = null;

    private bool $membershipLoaded = false;

    public function __construct(
        public readonly Document $document,
        public readonly User $actor,
        public readonly Organization $organization,
        public readonly CarbonImmutable $now,
        private readonly AccessChecker $access,
        private readonly ApprovalGate $approvals,
        public readonly bool $isReversal = false,
    ) {
        $this->locks = new LockSet;
    }

    public function settings(): OrganizationSettings
    {
        return $this->organization->settings();
    }

    public function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->document->occurred_at);
    }

    public function storeId(): ?int
    {
        return $this->document->store_id;
    }

    public function requireStoreId(): int
    {
        return $this->document->store_id ?? throw PostingException::because('no_store', 'В документе не указан магазин');
    }

    public function rate(): ?Rate
    {
        return $this->document->rate();
    }

    public function requireRate(): Rate
    {
        return $this->rate() ?? throw PostingException::because('no_rate', 'Не задан курс $. Установите курс в шапке системы');
    }

    /** Start of the business day of the document in the organization timezone. */
    public function businessDate(): CarbonImmutable
    {
        return $this->occurredAt()->setTimezone($this->organization->timezone)->startOfDay();
    }

    public function isOwner(): bool
    {
        return $this->access->isOwner($this->actor);
    }

    /** Plain permission check of the acting user (no limits, no approvals) — e.g. what an error message may show. */
    public function can(string $permission): bool
    {
        return $this->access->can($this->actor, $permission);
    }

    /** Is the store within the acting user's stores (memberships.store_ids, empty = all; TZ 03 §1, §18 p.4)? */
    public function actorMayUseStore(int $storeId): bool
    {
        if (! $this->membershipLoaded) {
            $this->membership = $this->access->membership($this->actor);
            $this->membershipLoaded = true;
        }

        return (bool) $this->membership?->isActive() && $this->membership->allowsStore($storeId);
    }

    /**
     * Check a permission (and a limit) for the acting user inside the posting transaction (TZ 03 §5.2 p.5).
     * Denied → AccessDenied. Over limit / «только заявкой» → ApprovalRequired unless the ApprovalGate approves it.
     */
    public function authorize(string $permission, ?Limit $limit = null, ?int $value = null, ?string $approvalType = null, ?string $message = null): void
    {
        $decision = $this->access->decide($this->actor, $permission, $limit, $value, $this->document->store_id);
        if ($decision->isDenied()) {
            throw AccessDenied::because('access_denied', $message ?? $decision->message, ['permission' => $permission]);
        }
        if ($decision->needsApproval()) {
            $type = $approvalType ?? $permission;
            if (! $this->approvals->isApproved($this->document, $type, $decision)) {
                throw new ApprovalRequired($decision, $type, $message ?? $decision->message);
            }
            $this->approvalUsed = true;
        }
    }

    /**
     * A rule that needs the owner's approval for everyone except the owner, regardless of limits
     * (e.g. «просроченный долг блокирует новый», TZ 03 §5.2 p.8).
     */
    public function requireApprovalUnlessOwner(string $permission, string $approvalType, string $message): void
    {
        if ($this->isOwner()) {
            return;
        }
        $decision = AccessDecision::approval($permission, null, null, null, $message);
        if (! $this->approvals->isApproved($this->document, $approvalType, $decision)) {
            throw new ApprovalRequired($decision, $approvalType, $message);
        }
        $this->approvalUsed = true;
    }

    public function approvalUsed(): bool
    {
        return $this->approvalUsed;
    }

    /** Common columns of a journal row. */
    public function entryDefaults(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'document_id' => $this->document->id,
            'store_id' => $this->document->store_id,
            'user_id' => $this->actor->id,
            'occurred_at' => $this->document->occurred_at,
            'recorded_at' => $this->now,
        ];
    }
}
