<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\ApprovalKinds;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Exceptions\ApprovalInvalidated;
use App\Domain\Approvals\Fingerprint\DocumentFingerprints;
use App\Domain\Approvals\Fingerprint\Fingerprint;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Notifications\Services\Recipients;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\AccessDecision;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Requests to the owner (TZ 03 §6, TZ 01 П9, TZ 04 Э28). Typical flow at the cash register:
 *
 *   try {
 *       $sale = $poster->post($draft, $seller);
 *   } catch (ApprovalRequired $e) {                          // «Скидка 15 000 ₸ больше вашего лимита 5 000 ₸»
 *       $request = $approvals->requestForDocument($draft, $seller, $reasonText);   // one request, all requirements
 *   }
 *   // poll every 3 s: $queries->waitState($request, $seller) → «Ждём ответа Кайрата… 0:42» / «Разрешено…»
 *   // after «Разрешено» (or «Применить» of a changed value to the SAME draft): $poster->post($draft, $seller)
 *
 * The owner: $queries->awaiting($owner), $presenter->forViewer($request, $owner), approve()/reject().
 * Timings (reminder 3 min, admins 5 min, expiry 30 min, approval valid 15 min): tick() every minute.
 */
class ApprovalService
{
    public function __construct(
        private readonly DatabaseApprovalGate $gate,
        private readonly DocumentFingerprints $fingerprints,
        private readonly ApprovalSummaries $summaries,
        private readonly DeciderPolicy $policy,
        private readonly AccessChecker $access,
        private readonly Notifier $notifier,
        private readonly Recipients $recipients,
    ) {}

    // ------------------------------------------------------------------ create

    /** Request for a draft whose posting needs approval. Runs a preflight to gather ALL requirements of the draft. */
    public function requestForDocument(Document $draft, User $requester, ?string $reasonText = null, ?string $reasonCode = null): ApprovalRequest
    {
        /** @var Document $draft */
        $draft = Document::query()->whereKey($draft->id)->firstOrFail(); // another firm's document: not found
        if (! $draft->isDraft()) {
            throw ApprovalException::because('not_draft', __('Документ уже проведён — заявка не нужна'));
        }
        if ($draft->created_by !== null && (int) $draft->created_by !== $requester->id) {
            throw AccessDenied::because('access_denied', __('Заявку по чужому черновику отправить нельзя'));
        }
        $this->assertMember($requester);
        if ($draft->created_by === null) {
            $draft->forceFill(['created_by' => $requester->id])->save();
        }

        $requirements = $this->gate->collect(fn () => app(Poster::class)->post($draft, $requester));
        if ($requirements === []) {
            throw ApprovalException::because('not_needed', __('Разрешение не нужно: документ можно провести'));
        }
        $described = $this->summaries->forDocument($draft, $requirements);
        $request = $this->create($requester, $requirements, $this->fingerprints->of($draft), $described,
            ['document_id' => $draft->id, 'store_id' => $draft->store_id], $reasonText, $reasonCode);
        $draft->forceFill(['approval_request_id' => $request->id])->save();

        return $request;
    }

    /** Request to reverse a posted document by someone whose role has «Сторно — заявкой» (TZ 03 №79, §6.1). */
    public function requestReversal(Document $original, User $requester, ReversalReason|string $reason, ?string $reasonText = null): ApprovalRequest
    {
        /** @var Document $original */
        $original = Document::query()->whereKey($original->id)->firstOrFail();
        if ($original->status !== DocumentStatus::Posted) {
            throw ApprovalException::because('not_posted', __('Сторнировать можно только проведённый документ'));
        }
        $this->assertMember($requester);
        $reason = $reason instanceof ReversalReason ? $reason : ReversalReason::from($reason);

        $requirements = $this->gate->collect(fn () => app(Reverser::class)->reverse($original, $reason, $reasonText, $requester));
        if ($requirements === []) {
            throw ApprovalException::because('not_needed', __('Разрешение не нужно: вы можете сторнировать документ сами'));
        }
        $described = $this->summaries->forReversal($original);
        $fingerprint = Fingerprint::fromData(['subject' => [$original->id, $original->number]]);

        return $this->create($requester, $requirements, $fingerprint, $described,
            ['subject_document_id' => $original->id, 'store_id' => $original->store_id],
            trim(($reason->value === 'other' ? '' : $this->reversalReasonLabel($reason).'. ').(string) $reasonText), $reason->value, forceReason: true);
    }

    /**
     * Request for an action that is not a document (price change L14, customer export, staff dismissal, new device…).
     * The module later calls useForAction() with the same payload before doing the action.
     */
    public function requestForAction(string $actionType, array $payload, User $requester, AccessDecision $decision, string $summary, ?int $storeId = null, ?string $reasonText = null, array $context = [], int $maxValue = 0): ApprovalRequest
    {
        $this->assertMember($requester);
        $requirements = [[
            'type' => $actionType, 'permission' => $decision->permission, 'limit' => $decision->limit?->value,
            'limit_value' => $decision->limitValue, 'actual_value' => $decision->actualValue, 'message' => $decision->message,
        ]];
        $key = $this->actionKey($payload);

        return $this->create($requester, $requirements, Fingerprint::fromData(['payload' => $payload]),
            ['summary' => $summary, 'context' => $context, 'sensitive' => [], 'max_value' => $maxValue, 'decider_checks' => []],
            ['action_type' => $actionType, 'action_key' => $key, 'store_id' => $storeId], $reasonText, null);
    }

    /** Consume the approval of a non-document action (one use, 15 min, same payload). */
    public function useForAction(string $actionType, array $payload, User $actor): ApprovalRequest
    {
        return DB::transaction(function () use ($actionType, $payload, $actor) {
            $request = ApprovalRequest::query()->where('action_type', $actionType)->where('action_key', $this->actionKey($payload))
                ->where('requested_by', $actor->id)->where('status', ApprovalStatus::Approved->value)
                ->orderByDesc('id')->lockForUpdate()->first();
            $decision = AccessDecision::approval($actionType, null, null, null, __('Нужно разрешение владельца'));
            if (! $request) {
                throw new ApprovalInvalidated($decision, $actionType, __('Нет разрешения на это действие. Отправьте заявку владельцу'));
            }
            if (! $request->isUsable()) {
                throw ApprovalInvalidated::lapsed($decision, $actionType, ApprovalSettings::minutes('valid_minutes'));
            }
            $request->forceFill(['status' => ApprovalStatus::Used->value, 'used_at' => now()])->save();
            activity('approvals')->performedOn($request)->causedBy($actor)->log('used');

            return $request;
        });
    }

    // ------------------------------------------------------------------ decide

    /**
     * «Разрешить» or «Изменить и разрешить» (TZ 03 §6.3). $changes: ['discount' => Money|int tiyn],
     * ['debt' => Money|int, 'debt_due' => 'Y-m-d'|Carbon] — only the values of the request's kinds, not above the asked.
     * $confirmed: the PIN / Face ID of the decider was checked by the screen (requests above 300 000 ₸ except discounts).
     */
    public function approve(ApprovalRequest|int $request, User $decider, array $changes = [], ?string $comment = null, bool $confirmed = false): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $decider, $changes, $comment, $confirmed) {
            $r = $this->lockForDecision($request);
            $changes = $this->normalizeChanges($r, $changes);
            if ($why = $this->policy->whyNot($decider, $r, $changes)) {
                throw AccessDenied::because('access_denied', $why);
            }
            if ($r->needs_confirmation && ! $confirmed) {
                throw ApprovalException::because('confirmation_required', __('Заявка на :amount — подтвердите PIN, чтобы разрешить', ['amount' => Money::kztMinor((int) $r->max_value)->format()]));
            }
            $released = [];
            foreach ($r->kinds() as $kind) {
                if (array_intersect(ApprovalKinds::changeable($kind), array_keys($changes))) {
                    $released = array_merge($released, ApprovalKinds::released($kind));
                }
            }
            $now = CarbonImmutable::now();
            $r->forceFill([
                'status' => ApprovalStatus::Approved->value,
                'decided_by' => $decider->id,
                'decided_at' => $now,
                'decision_comment' => $this->clean($comment),
                'approved_values' => $changes ?: null,
                'released_groups' => $released ? array_values(array_unique($released)) : null,
                'valid_until' => $now->addMinutes(ApprovalSettings::minutes('valid_minutes')),
            ])->save();
            activity('approvals')->performedOn($r)->causedBy($decider)
                ->withProperties(['kind' => $r->kind, 'changes' => $changes, 'comment' => $comment])->log($changes ? 'approved_with_change' : 'approved');

            $this->afterDecision($r, $decider, $this->approvedText($r));

            return $r;
        });
    }

    /** «Отклонить» with an optional reason («Слишком большая скидка», «Позвони мне», «Нет»). */
    public function reject(ApprovalRequest|int $request, User $decider, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $decider, $comment) {
            $r = $this->lockForDecision($request);
            if ($why = $this->policy->whyNot($decider, $r)) {
                throw AccessDenied::because('access_denied', $why);
            }
            $r->forceFill([
                'status' => ApprovalStatus::Rejected->value, 'decided_by' => $decider->id, 'decided_at' => now(),
                'decision_comment' => $this->clean($comment), 'closed_at' => now(),
            ])->save();
            activity('approvals')->performedOn($r)->causedBy($decider)->withProperties(['kind' => $r->kind, 'comment' => $comment])->log('rejected');

            $this->afterDecision($r, $decider, $this->rejectedText($r));

            return $r;
        });
    }

    /** «Отменить заявку» by the employee who sent it (pending, or approved and not used yet). */
    public function cancel(ApprovalRequest|int $request, User $by): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $by) {
            /** @var ApprovalRequest $r */
            $r = ApprovalRequest::query()->whereKey($request instanceof ApprovalRequest ? $request->id : $request)->lockForUpdate()->firstOrFail();
            if ((int) $r->requested_by !== $by->id) {
                throw AccessDenied::because('access_denied', __('Отменить заявку может только тот, кто её отправил'));
            }
            if (! in_array($r->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)) {
                throw ApprovalException::because('closed', __('Заявка уже закрыта: :status', ['status' => mb_strtolower($r->status->label())]));
            }
            $r->forceFill(['status' => ApprovalStatus::Cancelled->value, 'closed_at' => now()])->save();
            activity('approvals')->performedOn($r)->causedBy($by)->log('cancelled');
            $this->markRequestNotificationsRead($r);

            return $r;
        });
    }

    /**
     * The register rebuilt the check as a NEW draft (e.g. after «Применить»): move the approval to it. The new draft
     * must be the same operation (fingerprint, except groups released by the decider) of the same author.
     */
    public function rebind(ApprovalRequest|int $request, Document $newDraft, User $requester): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $newDraft, $requester) {
            /** @var ApprovalRequest $r */
            $r = ApprovalRequest::query()->whereKey($request instanceof ApprovalRequest ? $request->id : $request)->lockForUpdate()->firstOrFail();
            /** @var Document $doc */
            $doc = Document::query()->whereKey($newDraft->id)->firstOrFail();
            $old = $r->document_id ? Document::query()->find($r->document_id) : null;
            if ((int) $r->requested_by !== $requester->id || ! $doc->isDraft() || ! $old || $old->type !== $doc->type
                || ($doc->created_by !== null && (int) $doc->created_by !== $requester->id)) {
                throw AccessDenied::because('access_denied', __('Перенести разрешение на этот документ нельзя'));
            }
            if (! in_array($r->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)) {
                throw ApprovalException::because('closed', __('Заявка уже закрыта: :status', ['status' => mb_strtolower($r->status->label())]));
            }
            if ($this->fingerprints->of($doc)->differences($r->fingerprint ?? [], $r->released_groups ?? []) !== []) {
                throw ApprovalException::because('changed', __('Чек изменился после разрешения. Отправьте заявку снова'));
            }
            $old->isDraft() && $old->forceFill(['approval_request_id' => null])->save();
            $r->forceFill(['document_id' => $doc->id])->save();
            $doc->forceFill(['approval_request_id' => $r->id, 'created_by' => $requester->id])->save();

            return $r;
        });
    }

    // ------------------------------------------------------------------ timings

    /**
     * Every minute (scheduler, per organization): expiry after 30 min, lapse of unused approvals after 15 min,
     * reminder after 3 min, escalation to admins after 5 min (TZ 03 §6.4).
     *
     * @return array{expired: int, lapsed: int, reminded: int, escalated: int}
     */
    public function tick(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $counts = ['expired' => 0, 'lapsed' => 0, 'reminded' => 0, 'escalated' => 0];

        foreach (ApprovalRequest::query()->where('status', ApprovalStatus::Pending->value)->where('expires_at', '<=', $now)->orderBy('id')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $now, &$counts) {
                $r = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();
                if ($r?->status !== ApprovalStatus::Pending) {
                    return;
                }
                $r->forceFill(['status' => ApprovalStatus::Expired->value, 'closed_at' => $now])->save();
                activity('approvals')->performedOn($r)->log('expired');
                $this->markRequestNotificationsRead($r);
                $this->notifier->toUser((int) $r->requested_by, NotificationType::ApprovalExpired,
                    __('Заявка истекла: :what. Отправьте снова, если ещё нужно', ['what' => ApprovalKinds::label($r->kind)]), $r->summary,
                    ['approval_request_id' => $r->id, 'document_id' => $r->document_id]);
                $counts['expired']++;
            });
        }

        foreach (ApprovalRequest::query()->where('status', ApprovalStatus::Approved->value)->where('valid_until', '<=', $now)->orderBy('id')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $now, &$counts) {
                $r = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();
                if ($r?->status !== ApprovalStatus::Approved) {
                    return;
                }
                $r->forceFill(['status' => ApprovalStatus::Lapsed->value, 'closed_at' => $now])->save();
                activity('approvals')->performedOn($r)->log('lapsed');
                $counts['lapsed']++;
            });
        }

        $reminderAt = $now->subMinutes(ApprovalSettings::minutes('reminder_minutes'));
        foreach (ApprovalRequest::query()->open()->whereNull('reminded_at')->where('created_at', '<=', $reminderAt)->orderBy('id')->get() as $r) {
            $minutes = max(1, (int) floor($r->created_at->diffInMinutes($now)));
            $this->notifier->toOwners(NotificationType::ApprovalReminder,
                __(':name ждёт уже :min мин.: :what', ['name' => Recipients::firstName($r->requester), 'min' => $minutes, 'what' => ApprovalKinds::label($r->kind)]),
                $r->summary, ['approval_request_id' => $r->id, 'headline' => $r->headline]);
            $r->forceFill(['reminded_at' => $now])->save();
            activity('approvals')->performedOn($r)->log('reminded');
            $counts['reminded']++;
        }

        $escalateAt = $now->subMinutes(ApprovalSettings::minutes('escalate_minutes'));
        foreach (ApprovalRequest::query()->open()->whereNull('escalated_at')->where('created_at', '<=', $escalateAt)->orderBy('id')->get() as $r) {
            $deciders = collect();
            if (ApprovalSettings::get('escalate_to_admins') && ! $r->isOwnerOnly()) {
                $owners = $this->recipients->owners()->pluck('id')->all();
                $deciders = $this->recipients->withPermission('approval.decide', $r->store_id)
                    ->reject(fn (User $u) => in_array($u->id, $owners, true))
                    ->filter(fn (User $u) => $this->policy->canDecide($u, $r));
            }
            $this->notifier->toUsers($deciders, NotificationType::ApprovalEscalated,
                __('Заявка ждёт решения: :name — :what', ['name' => Recipients::firstName($r->requester), 'what' => ApprovalKinds::label($r->kind)]),
                $r->summary, ['approval_request_id' => $r->id, 'headline' => $r->headline]);
            $r->forceFill(['escalated_at' => $now])->save();
            activity('approvals')->performedOn($r)->withProperties(['deciders' => $deciders->pluck('id')->all()])->log('escalated');
            $counts['escalated']++;
        }

        return $counts;
    }

    // ------------------------------------------------------------------ texts

    /** «Разрешено: скидка 15 000 ₸» / «Разрешено: скидка 10 000 ₸ (вы просили 15 000 ₸)» (TZ 03 §6.2 p.5, Э28.2). */
    public function approvedText(ApprovalRequest $r): string
    {
        $changes = $r->approved_values ?? [];
        if ($changes) {
            $now = [];
            $was = [];
            foreach ($changes as $key => $value) {
                $now[] = $this->valueText($key, $value);
                $was[] = $this->valueText($key, $r->values[$key] ?? null, false);
            }

            return __('Разрешено: :now (вы просили :was)', ['now' => implode(', ', $now), 'was' => implode(', ', array_filter($was))]);
        }
        $main = $this->mainValueKey($r);

        return $main ? __('Разрешено: :what', ['what' => $this->valueText($main, $r->values[$main] ?? null)])
            : __('Разрешено: :what', ['what' => ApprovalKinds::label($r->kind)]);
    }

    public function rejectedText(ApprovalRequest $r): string
    {
        return $r->decision_comment ? __('Отклонено: «:reason»', ['reason' => $r->decision_comment]) : __('Отклонено');
    }

    private function valueText(string $key, mixed $value, bool $withTitle = true): string
    {
        if ($value === null) {
            return '';
        }
        [$unit, $title] = ApprovalKinds::VALUE_KEYS[$key] ?? ['money', $key];
        $text = $unit === 'date' ? CarbonImmutable::parse((string) $value)->format('d.m.Y') : Money::kztMinor((int) $value)->format();
        if ($key === 'debt' && $withTitle) {
            return __('долг до :amount', ['amount' => $text]);
        }

        return $withTitle ? __($title).' '.$text : $text;
    }

    private function mainValueKey(ApprovalRequest $r): ?string
    {
        foreach (ApprovalKinds::changeable($r->kind) as $key) {
            if (isset($r->values[$key])) {
                return $key;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ internals

    private function create(User $requester, array $requirements, Fingerprint $fingerprint, array $described, array $subject, ?string $reasonText, ?string $reasonCode, bool $forceReason = false): ApprovalRequest
    {
        return DB::transaction(function () use ($requester, $requirements, $fingerprint, $described, $subject, $reasonText, $reasonCode, $forceReason) {
            $now = CarbonImmutable::now();
            // serialize concurrent requests of one employee (double tap)
            DB::table('memberships')->where('organization_id', $requester->getAttribute('organization_id') ?? DB::raw('organization_id'))->where('user_id', $requester->id)->lockForUpdate()->first();

            $sameSubject = fn ($q) => isset($subject['document_id']) ? $q->where('document_id', $subject['document_id'])
                : (isset($subject['subject_document_id']) ? $q->where('subject_document_id', $subject['subject_document_id'])
                    : $q->where('action_type', $subject['action_type'])->where('action_key', $subject['action_key']));
            // «Отправить снова»: a new request for the same operation supersedes the open one
            ApprovalRequest::query()->where('requested_by', $requester->id)
                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
                ->where($sameSubject)->lockForUpdate()->get()
                ->each(function (ApprovalRequest $old) use ($now) {
                    $old->forceFill(['status' => ApprovalStatus::Cancelled->value, 'closed_at' => $now])->save();
                    activity('approvals')->performedOn($old)->log('superseded');
                    $this->markRequestNotificationsRead($old);
                });

            $max = (int) ApprovalSettings::get('max_pending');
            $open = ApprovalRequest::query()->where('requested_by', $requester->id)->open()->count();
            if ($open >= $max) {
                throw ApprovalException::because('too_many', __('У вас :n заявки ждут ответа. Дождитесь или отмените одну', ['n' => $open]));
            }

            $kind = ApprovalSummaries::mainKind($requirements);
            $needsReason = $forceReason || collect($requirements)->contains(fn ($r) => ApprovalKinds::reasonRequired($r['type']));
            $reasonText = $this->clean($reasonText);
            if ($needsReason && mb_strlen((string) $reasonText) < 5) {
                throw ApprovalException::because('reason_required', __('Напишите причину заявки (не короче 5 символов)'));
            }
            $values = $fingerprint->values;
            if ($described['decider_checks'] ?? []) {
                $values['decider_checks'] = $described['decider_checks'];
            }
            $confirmKinds = collect($requirements)->contains(fn ($r) => ApprovalKinds::countsForConfirmation($r['type']));

            $request = new ApprovalRequest;
            $request->forceFill($subject + [
                'kind' => $kind,
                'status' => ApprovalStatus::Pending->value,
                'requested_by' => $requester->id,
                'requirements' => $requirements,
                'fingerprint' => $fingerprint->groups,
                'values' => $values,
                'headline' => ApprovalSummaries::headline($requester, $kind),
                'summary' => $described['summary'],
                'context' => $described['context'] ?: null,
                'sensitive' => $described['sensitive'] ?: null,
                'max_value' => (int) $described['max_value'],
                'needs_confirmation' => $confirmKinds && (int) $described['max_value'] > ApprovalSettings::confirmThreshold(),
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'expires_at' => $now->addMinutes(ApprovalSettings::minutes('lifetime_minutes')),
            ])->save();
            activity('approvals')->performedOn($request)->causedBy($requester)
                ->withProperties(['kind' => $kind, 'requirements' => array_column($requirements, 'type'), 'max_value' => $request->max_value])->log('created');

            $this->notifier->toOwners(NotificationType::ApprovalNew, $request->headline, $request->summary,
                ['approval_request_id' => $request->id, 'document_id' => $request->document_id, 'headline' => $request->headline]);

            return $request;
        });
    }

    private function lockForDecision(ApprovalRequest|int $request): ApprovalRequest
    {
        /** @var ApprovalRequest $r */
        $r = ApprovalRequest::query()->whereKey($request instanceof ApprovalRequest ? $request->id : $request)->lockForUpdate()->firstOrFail();
        $status = $r->effectiveStatus();
        if ($status !== ApprovalStatus::Pending) {
            throw ApprovalException::because('closed', match ($status) {
                ApprovalStatus::Expired => __('Заявка истекла — сотрудник может отправить её снова'),
                ApprovalStatus::Cancelled => __('Сотрудник отменил заявку'),
                default => __('Заявка уже решена: :status', ['status' => mb_strtolower($status->label())]),
            });
        }

        return $r;
    }

    /** @return array<string, int|string> */
    private function normalizeChanges(ApprovalRequest $r, array $changes): array
    {
        $allowed = $r->changeableKeys();
        $out = [];
        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($key, $allowed, true)) {
                throw ApprovalException::because('change_not_allowed', __('Эту заявку можно только разрешить или отклонить'));
            }
            $unit = ApprovalKinds::VALUE_KEYS[$key][0] ?? 'money';
            if ($unit === 'date') {
                $date = $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
                if ($date->startOfDay()->lt(CarbonImmutable::now()->startOfDay()->addDay())) {
                    throw ApprovalException::because('bad_change', __('Срок долга — не раньше завтрашнего дня'));
                }
                $out[$key] = $date->format('Y-m-d');

                continue;
            }
            $minor = $value instanceof Money ? $value->amount : (int) $value;
            $asked = (int) ($r->values[$key] ?? 0);
            if ($minor <= 0 || $minor % Money::TENGE !== 0) {
                throw ApprovalException::because('bad_change', __('Укажите сумму в целых тенге больше нуля'));
            }
            if ($minor > $asked) {
                throw ApprovalException::because('bad_change', __('Можно разрешить не больше, чем просили: :amount', ['amount' => Money::kztMinor($asked)->format()]));
            }
            if ($minor !== $asked) {
                $out[$key] = $minor;
            }
        }

        return $out;
    }

    private function afterDecision(ApprovalRequest $r, User $decider, string $text): void
    {
        $this->markRequestNotificationsRead($r);
        $this->notifier->toUser((int) $r->requested_by, NotificationType::ApprovalDecided, $text, $r->summary,
            ['approval_request_id' => $r->id, 'document_id' => $r->document_id]);
        if (! $this->access->isOwner($decider)) {
            // TZ 03 §6.6 p.4: the owner learns about every decision of an admin — in the feed, without a push
            $this->notifier->toOwners(NotificationType::ApprovalDecidedByOther,
                __(':decider: :text (:name)', ['decider' => Recipients::firstName($decider), 'text' => $text, 'name' => Recipients::firstName($r->requester)]),
                $r->summary, ['approval_request_id' => $r->id, 'silent' => true]);
        }
    }

    /** The «new request / reminder / escalation» rows of a closed request are no longer actionable. */
    private function markRequestNotificationsRead(ApprovalRequest $r): void
    {
        AppNotification::query()->where('approval_request_id', $r->id)->whereNull('read_at')
            ->whereIn('type', [NotificationType::ApprovalNew->value, NotificationType::ApprovalReminder->value, NotificationType::ApprovalEscalated->value])
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    private function assertMember(User $user): void
    {
        if (! $this->recipients->isActiveMember($user)) {
            throw AccessDenied::because('access_denied', __('Доступ закрыт. Обратитесь к владельцу'));
        }
    }

    private function actionKey(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    private function reversalReasonLabel(ReversalReason $reason): string
    {
        return method_exists($reason, 'label') ? $reason->label() : $reason->value;
    }
}
