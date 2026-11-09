<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\ApprovalKinds;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalInvalidated;
use App\Domain\Approvals\Fingerprint\DocumentFingerprints;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\ApprovalGate;
use App\Domain\Tenancy\Access\AccessDecision;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The Approvals implementation of the core ApprovalGate (TZ 03 §6.4–6.5), bound instead of NoApprovals.
 *
 * Called by PostingContext::authorize() inside the posting transaction when a limit is exceeded:
 *  1. finds the request of THIS document (documents.approval_request_id, or for a reversal the request about the
 *     original document) made by the author of the draft; a request of another document / firm is ignored;
 *  2. it must be approved, not older than 15 minutes, not used by another posting (one approval = one posting);
 *  3. it must cover this type + permission (any limit of it: L1/L2/L3 of a discount);
 *  4. the fingerprint of the document must be the same, except groups released by «Разрешить с изменением»,
 *     whose values must not exceed what was allowed («разрешение на 5 000 ₸ не годится на 9 000 ₸»);
 *  5. marks the request used by this document (rolled back with the posting if it fails) and sets
 *     documents.approval_request_id (the Poster keeps it: the approved check does not spend daily limits).
 *
 * collect() — «preflight»: runs an operation (Poster::post / Reverser::reverse) in a transaction that is always rolled
 * back, answering «approved» to every check and recording what would need an approval. This gives ONE request with
 * all requirements of a check (discount + debt) instead of a chain of requests.
 */
class DatabaseApprovalGate implements ApprovalGate
{
    /** @var array<string, array>|null collect mode when not null */
    private ?array $collected = null;

    public function __construct(private readonly DocumentFingerprints $fingerprints) {}

    public function isApproved(Document $document, string $approvalType, AccessDecision $decision): bool
    {
        if ($this->collected !== null) {
            $this->record($approvalType, $decision);

            return true;
        }

        return $this->check($document, $approvalType, $decision);
    }

    /**
     * @return list<array{type: string, permission: string, limit: ?string, limit_value: ?int, actual_value: ?int, message: string}>
     */
    public function collect(callable $operation): array
    {
        if ($this->collected !== null) {
            throw new LogicException('Approval preflight cannot be nested.');
        }
        $this->collected = [];
        try {
            DB::transaction(function () use ($operation) {
                $operation();
                throw new PreflightRollback;
            });
        } catch (PreflightRollback) {
            // expected: nothing of the preflight stays in the database
        } finally {
            $requirements = array_values($this->collected ?? []);
            $this->collected = null;
        }

        return $requirements;
    }

    public function isCollecting(): bool
    {
        return $this->collected !== null;
    }

    private function record(string $type, AccessDecision $decision): void
    {
        // «только заявкой» decisions carry no limit: keep each checked value (amount, days) as its own requirement
        $key = $type.'|'.$decision->permission.'|'.($decision->limit?->value ?? '-:'.$decision->actualValue);
        $row = [
            'type' => $type,
            'permission' => $decision->permission,
            'limit' => $decision->limit?->value,
            'limit_value' => $decision->limitValue,
            'actual_value' => $decision->actualValue,
            'message' => $decision->message,
        ];
        if (! isset($this->collected[$key]) || (int) $row['actual_value'] > (int) $this->collected[$key]['actual_value']) {
            $this->collected[$key] = $row;
        }
    }

    private function check(Document $document, string $type, AccessDecision $decision): bool
    {
        $request = $this->resolve($document);
        if (! $request) {
            return false;
        }
        /** @var ApprovalRequest $request */
        $request = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->first();
        $usedHere = $request->status === ApprovalStatus::Used && (int) $request->used_document_id === (int) $document->id;
        if ($request->status !== ApprovalStatus::Approved && ! $usedHere) {
            return false; // pending / rejected / expired / used elsewhere → the document needs (a new) request
        }
        if (! $request->covers($type, $decision->permission)) {
            return false;
        }
        $now = CarbonImmutable::now();
        if (! $usedHere && ($request->valid_until === null || $request->valid_until->lessThanOrEqualTo($now))) {
            throw ApprovalInvalidated::lapsed($decision, $type, (int) round($request->decided_at?->diffInMinutes($request->valid_until) ?? 15));
        }
        $this->assertSameOperation($document, $request, $decision, $type);

        if (! $usedHere) {
            $request->forceFill(['status' => ApprovalStatus::Used->value, 'used_document_id' => $document->id, 'used_at' => $now])->save();
            activity('approvals')->performedOn($request)->withProperties(['document_id' => $document->id, 'kind' => $request->kind])->log('used');
        }
        $document->approval_request_id = $request->id;

        return true;
    }

    private function resolve(Document $document): ?ApprovalRequest
    {
        if ($document->type === DocumentType::Reversal) {
            return ApprovalRequest::query()
                ->where('subject_document_id', $document->reversal_of_document_id)
                ->when($document->created_by, fn ($q) => $q->where('requested_by', $document->created_by))
                ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Used->value])
                ->where(fn ($q) => $q->whereNull('used_document_id')->orWhere('used_document_id', $document->id))
                ->orderByDesc('id')->first();
        }
        $query = ApprovalRequest::query()->where('document_id', $document->id)
            ->when($document->created_by, fn ($q) => $q->where('requested_by', $document->created_by));
        if ($document->approval_request_id) {
            // an id that belongs to another document (or firm: global scope) authorizes nothing
            return $query->whereKey($document->approval_request_id)->first();
        }

        return $query->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Used->value])->orderByDesc('id')->first();
    }

    private function assertSameOperation(Document $document, ApprovalRequest $request, AccessDecision $decision, string $type): void
    {
        if ($document->type === DocumentType::Reversal) {
            return; // bound to the original document by subject_document_id
        }
        $current = $this->fingerprints->of($document);
        $released = $request->released_groups ?? [];
        if ($current->differences($request->fingerprint ?? [], $released) !== []) {
            throw ApprovalInvalidated::changed($decision, $type, $document->type === DocumentType::Sale);
        }
        foreach ($request->approved_values ?? [] as $key => $allowed) {
            $value = $current->values[$key] ?? null;
            if ($allowed === null || $value === null) {
                continue;
            }
            $unit = ApprovalKinds::VALUE_KEYS[$key][0] ?? 'money';
            $over = $unit === 'date' ? (string) $value > (string) $allowed : (int) $value > (int) $allowed;
            if ($over) {
                $what = __(ApprovalKinds::VALUE_KEYS[$key][1] ?? $key).' '.($unit === 'date'
                    ? CarbonImmutable::parse((string) $allowed)->format('d.m.Y')
                    : Money::kztMinor((int) $allowed)->format());
                throw ApprovalInvalidated::aboveApproved($decision, $type, $what);
            }
        }
    }
}
