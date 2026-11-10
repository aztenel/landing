<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\AccountingPeriod;
use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Accounting periods = calendar months of the organization (TZ 02 §9). organizations.closed_until is the fast
 * copy checked by Poster and by the DB trigger pm_documents_period_guard().
 */
class AccountingPeriods
{
    public function __construct(private readonly AccessChecker $access) {}

    public function closedUntil(): ?CarbonImmutable
    {
        $org = Organization::query()->find(CurrentOrganization::instance()->requireId());

        return $org->closed_until ? CarbonImmutable::parse($org->closed_until->format('Y-m-d'), $org->timezone)->endOfDay() : null;
    }

    public function isOpen(CarbonInterface $moment): bool
    {
        $closed = $this->closedUntil();

        return $closed === null || CarbonImmutable::parse($moment)->greaterThan($closed);
    }

    public function assertOpen(CarbonInterface $moment): void
    {
        if (! $this->isOpen($moment)) {
            $org = CurrentOrganization::instance()->require();
            throw PeriodClosed::because('period_closed', sprintf(
                'Период закрыт по %s включительно: документ датой %s провести нельзя',
                $this->closedUntil()->format('d.m.Y'),
                CarbonImmutable::parse($moment)->setTimezone($org->timezone)->format('d.m.Y'),
            ));
        }
    }

    /**
     * Close a month (only the owner, only a past month, only after the previous ones). Locks the organization row:
     * postings that check the period wait (TZ 02 §12.1). The checklist of §9.2 p.5 beyond «no drafts» is the
     * Reports module's job.
     */
    public function close(CarbonInterface $month, User $actor): AccountingPeriod
    {
        $orgId = CurrentOrganization::instance()->requireId();
        if (! $this->access->can($actor, 'period.close')) {
            throw AccessDenied::because('access_denied', 'Закрывает период только владелец');
        }

        return DB::transaction(function () use ($month, $actor, $orgId) {
            $org = Organization::query()->whereKey($orgId)->lockForUpdate()->firstOrFail();
            $tz = $org->timezone;
            $start = CarbonImmutable::parse($month)->setTimezone($tz)->startOfMonth();
            $end = $start->endOfMonth();

            if ($end->greaterThanOrEqualTo(CarbonImmutable::now($tz)->startOfMonth())) {
                throw PostingException::because('period_current', 'Закрыть можно только прошедший месяц');
            }
            if ($org->closed_until && $start->lessThanOrEqualTo(CarbonImmutable::parse($org->closed_until->format('Y-m-d'), $tz))) {
                throw PostingException::because('period_already_closed', 'Этот месяц уже закрыт');
            }
            // months are closed only in order (audit №46): the next one after the last closed month
            if ($org->closed_until) {
                $next = CarbonImmutable::parse($org->closed_until->format('Y-m-d'), $tz)->addDay()->startOfMonth();
                if (! $start->equalTo($next)) {
                    throw PostingException::because('period_order', 'Месяцы закрываются по порядку: сначала закройте '.$next->locale('ru')->translatedFormat('F Y'));
                }
            }
            $drafts = Document::query()->where('status', DocumentStatus::Draft->value)
                ->whereBetween('occurred_at', [$start->utc(), $end->utc()])->count();
            if ($drafts > 0) {
                throw PostingException::because('period_has_drafts', "В периоде есть черновики ({$drafts}): проведите или удалите их");
            }

            $org->forceFill(['closed_until' => $end->format('Y-m-d')])->save();
            CurrentOrganization::instance()->set($org);

            return AccountingPeriod::query()->updateOrCreate(
                ['month' => $start->format('Y-m-d')],
                ['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()],
            );
        });
    }

    /** Reopen the last closed month (owner, with a reason). */
    public function reopenLast(User $actor, string $reason): AccountingPeriod
    {
        $orgId = CurrentOrganization::instance()->requireId();
        if (! $this->access->can($actor, 'period.close')) {
            throw AccessDenied::because('access_denied', 'Открывает период только владелец');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw PostingException::because('reason_required', 'Укажите причину открытия периода');
        }

        return DB::transaction(function () use ($actor, $reason, $orgId) {
            $org = Organization::query()->whereKey($orgId)->lockForUpdate()->firstOrFail();
            if (! $org->closed_until) {
                throw PostingException::because('nothing_closed', 'Закрытых периодов нет');
            }
            $start = CarbonImmutable::parse($org->closed_until->format('Y-m-d'))->startOfMonth();
            $previousEnd = $start->subDay();
            $hasPrevious = AccountingPeriod::query()->where('month', $start->subMonth()->format('Y-m-d'))->where('status', 'closed')->exists();
            $org->forceFill(['closed_until' => $hasPrevious ? $previousEnd->format('Y-m-d') : null])->save();
            CurrentOrganization::instance()->set($org);

            $period = AccountingPeriod::query()->where('month', $start->format('Y-m-d'))->firstOrFail();
            $period->forceFill(['status' => 'open', 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason])->save();

            return $period;
        });
    }
}
