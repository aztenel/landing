<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Limit;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who may answer a request (TZ 03 §6.5 p.4, §6.6, TZ 04 Э25.3):
 *  - never the requester himself; only an active member with approval.decide;
 *  - the owner — always;
 *  - anyone else (admin) — only after 5 minutes without an answer (if «через 5 минут — и Админ» is on), never for
 *    owner-only types (below cost, export, dismissal, integrator, new device), and only if EVERY requirement fits
 *    the decider's OWN limits (admin with a 30 000 ₸ discount limit may allow 15 000 ₸, not 40 000 ₸ — such a
 *    request is not even shown to him).
 */
class DeciderPolicy
{
    public function __construct(private readonly AccessChecker $access) {}

    public function canDecide(User $decider, ApprovalRequest $request, array $changes = []): bool
    {
        return $this->whyNot($decider, $request, $changes) === null;
    }

    /** null = may decide; otherwise the reason for the screen. */
    public function whyNot(User $decider, ApprovalRequest $request, array $changes = [], ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        if ((int) $request->requested_by === $decider->id) {
            return __('Свою заявку решить нельзя');
        }
        $right = $this->access->decide($decider, 'approval.decide', storeId: $request->store_id);
        if ($right->isDenied()) {
            return $right->message ?: __('Нет права решать заявки');
        }
        if ($this->access->isOwner($decider)) {
            return null;
        }
        if ($request->isOwnerOnly()) {
            return __('Эту заявку решает только владелец');
        }
        if (! ApprovalSettings::get('escalate_to_admins')) {
            return __('Заявки решает только владелец');
        }
        if ($request->created_at->copy()->addMinutes(ApprovalSettings::minutes('escalate_minutes'))->greaterThan($now)) {
            return __('Заявка ещё у владельца: если он не ответит за :min минут, её сможете решить вы', ['min' => ApprovalSettings::minutes('escalate_minutes')]);
        }
        foreach ($request->requirements ?? [] as $req) {
            $limit = $req['limit'] ? Limit::from($req['limit']) : null;
            $value = $this->valueFor($req, $request, $changes);
            $decision = $this->access->decide($decider, $req['permission'], $limit, $limit ? $value : null, $request->store_id);
            if (! $decision->isAllowed()) {
                return __('Сумма больше вашего лимита — заявку решает владелец');
            }
        }
        // «только заявкой» for the requester carries no limit: the limits that matter for the decider are given by
        // the document (return: amount + days, reversal: days) — values.decider_checks
        foreach ($request->values['decider_checks'] ?? [] as [$permission, $limitCode, $value]) {
            $decision = $this->access->decide($decider, $permission, Limit::from($limitCode), (int) $value, $request->store_id);
            if (! $decision->isAllowed()) {
                return __('Сумма больше вашего лимита — заявку решает владелец');
            }
        }

        return null;
    }

    /** The value checked against the decider's own limit (day limits: this operation only). */
    private function valueFor(array $req, ApprovalRequest $request, array $changes): ?int
    {
        $values = $request->values ?? [];
        $actual = $req['actual_value'] === null ? null : (int) $req['actual_value'];
        $discount = isset($changes['discount']) ? (int) $changes['discount'] : ($values['discount'] ?? null);

        return match ($req['limit']) {
            'L1', 'L3' => $discount !== null ? (int) $discount : $actual,
            'L2' => ($actual !== null && isset($changes['discount']) && ($values['discount'] ?? 0) > 0)
                ? intdiv($actual * (int) $changes['discount'] + (int) $values['discount'] - 1, (int) $values['discount'])
                : $actual,
            'L4' => ($actual !== null && isset($changes['debt'])) ? $actual - ((int) ($values['debt'] ?? 0) - (int) $changes['debt']) : $actual,
            'L5' => isset($changes['debt_due'])
                ? (int) abs(CarbonImmutable::now()->startOfDay()->diffInDays(CarbonImmutable::parse((string) $changes['debt_due'])->startOfDay()))
                : $actual,
            'L11' => isset($values['expense']) ? (int) $values['expense'] : $actual,
            default => $actual,
        };
    }
}
