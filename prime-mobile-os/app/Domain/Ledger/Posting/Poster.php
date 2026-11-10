<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Models\InventoryMovement;
use App\Support\Outbox\Outbox;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Events\DocumentPosted;
use App\Domain\Ledger\Exceptions\DocumentNotDraft;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Services\AccountingPeriods;
use App\Domain\Ledger\Services\DocumentNumberAllocator;
use App\Domain\Money\Services\ExchangeRates;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\CrossOrganizationViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posting = ONE database transaction (TZ 02 §2.2):
 *
 *   0. lock the document row; already posted → return it (idempotency, §2.4); not a draft → error
 *   1. business date rules: not in the future, backdating only with doc.backdate (≤ N days, reason), open period (§9)
 *   2. exchange rate of the document = the effective rate at posting time (unless set manually by the owner)
 *   3. handler->validate()   structural checks
 *   4. handler->lockPlan()   + LockManager: rows locked in the global order, then the number counter and the chain lock
 *   5. handler->post()       re-checks under locks, permissions/limits, journal entries via the ledgers
 *   6. number from document_counters, status posted, posted_at, posted_by; audit log
 *   7. after commit: DocumentPosted event (storefront, push, printing are listeners — never inside the transaction)
 *
 * Any exception rolls everything back; the document stays a draft without a number.
 */
class Poster
{
    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly LockManager $locks,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AccountingPeriods $periods,
        private readonly ExchangeRates $rates,
        private readonly AccessChecker $access,
        private readonly ApprovalGate $approvals,
    ) {}

    /**
     * Several documents as ONE operation (audit №20): exchange (return + new sale), trade-in act + sale. Either all of
     * them are posted or none: one outer transaction, each document posted in it in the given order (the act / the
     * return first). A deadlock retries the whole group.
     *
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    public function postMany(array $documents, User $actor): array
    {
        return TransactionRetry::run(fn () => array_map(fn (Document $d) => $this->post($d, $actor), $documents));
    }

    public function post(Document $document, User $actor): Document
    {
        $organization = CurrentOrganization::instance()->require();
        if ((int) $document->organization_id !== $organization->id) {
            throw CrossOrganizationViolation::make(Document::class, (int) $document->organization_id, $organization->id);
        }
        if ($document->type === DocumentType::Reversal) {
            throw PostingException::because('reversal_via_reverser', 'Сторно проводится через Reverser::reverse()');
        }
        $handler = $this->handlers->for($document->type);

        return TransactionRetry::run(function () use ($document, $actor, $organization, $handler) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            // lock order step 1 (TZ 02 §12.1, §12.3): a period close (FOR UPDATE of this row) waits for postings in
            // flight, and a posting that starts during a close sees the new closed_until
            DB::table('organizations')->where('id', $organization->id)->lock('for key share')->value('id');

            /** @var Document $doc */
            $doc = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status !== DocumentStatus::Draft) {
                if ($doc->status === DocumentStatus::Posted) {
                    return $doc; // second «Провести» with the same document / idempotency key
                }
                throw DocumentNotDraft::because('not_draft', "Документ {$doc->number} уже сторнирован");
            }

            $now = CarbonImmutable::now();
            if (! $doc->occurred_at_manual) {
                // nobody chose the date: the document happens when it is posted (audit №19), not when the draft was made
                $doc->forceFill(['occurred_at' => $now])->save();
            }
            $ctx = new PostingContext($doc, $actor, $organization, $now, $this->access, $this->approvals);

            $this->checkActorAndStore($doc, $actor);
            $this->checkBusinessDate($doc, $ctx);
            $this->fixExchangeRate($doc, $ctx, $handler);

            $handler->validate($doc, $ctx);
            $plan = $handler->lockPlan($doc, $ctx);
            $year = (int) $ctx->occurredAt()->setTimezone($organization->timezone)->format('Y');
            $ctx->locks = $this->locks->acquire($plan, $organization->id, $doc->type->prefix(), $year);

            $handler->post($doc, $ctx);
            $doc->shift_id = ShiftGuard::check($doc, $handler->shiftRequirement($doc), $doc);

            $doc->forceFill([
                'number' => $this->numbers->issue($ctx->locks->counter()),
                'number_year' => $year,
                'status' => DocumentStatus::Posted->value,
                'posted_at' => $now,
                'posted_by' => $actor->id,
                // the request id comes with the draft (i.e. from the client). It stays only if an approval really
                // authorized something here; otherwise a made-up id would take the document out of the daily
                // limits L3/L11, which skip approved documents (TZ 03 §5).
                'approval_request_id' => $ctx->approvalUsed() ? $doc->approval_request_id : null,
            ])->save();

            activity('documents')
                ->performedOn($doc)
                ->causedBy($actor)
                ->withProperties(['organization_id' => $organization->id, 'type' => $doc->type->value, 'number' => $doc->number])
                ->log('posted');

            // reliable announcement for the site and other listeners, in this very transaction (audit №5)
            Outbox::record('document.posted', [
                'document_id' => $doc->id, 'type' => $doc->type->value, 'number' => $doc->number,
                'stock_unit_ids' => InventoryMovement::query()->where('document_id', $doc->id)->whereNotNull('stock_unit_id')->distinct()->pluck('stock_unit_id')->all(),
            ], 'document', $doc->id);

            $id = $doc->id;
            $orgId = $organization->id;
            $type = $doc->type;
            DB::afterCommit(fn () => event(new DocumentPosted($id, $orgId, $type)));

            return $doc;
        });
    }

    /**
     * Whatever the handler checks: only an active member of this organization posts (an integrator, a dismissed or
     * blocked employee, a user of another firm never does — TZ 03 §7.4, §13 p.4), and the store of the document is a
     * store of this organization the actor may work in (TZ 03 §18 p.4).
     */
    private function checkActorAndStore(Document $doc, User $actor): void
    {
        $membership = $this->access->membership($actor);
        if (! $membership || ! $membership->isActive()) {
            throw AccessDenied::because('access_denied', 'Доступ закрыт. Обратитесь к владельцу');
        }
        if ($doc->store_id === null) {
            return;
        }
        if (! Store::query()->whereKey($doc->store_id)->exists()) {
            throw PostingException::because('store_not_found', 'Магазин не найден в этой фирме');
        }
        if (! $this->access->isOwner($actor) && ! $membership->allowsStore((int) $doc->store_id)) {
            throw AccessDenied::because('access_denied', 'Нет доступа к этому магазину');
        }
    }

    private function checkBusinessDate(Document $doc, PostingContext $ctx): void
    {
        $settings = $ctx->settings();
        $grace = $settings->backdateGraceMinutes();
        $occurred = $ctx->occurredAt();

        if ($occurred->greaterThan($ctx->now->addMinutes($grace))) {
            throw PostingException::because('future_date', 'Дата документа в будущем');
        }
        $this->periods->assertOpen($occurred);
        if ($occurred->lessThan($ctx->now->subMinutes($grace))) {
            if (! $this->access->can($ctx->actor, 'doc.backdate')) {
                throw PostingException::because('backdate_denied', 'Задним числом проводят только админ и владелец');
            }
            $tz = $ctx->organization->timezone;
            $days = (int) abs($occurred->setTimezone($tz)->startOfDay()->diffInDays($ctx->now->setTimezone($tz)->startOfDay()));
            if ($days > $settings->backdateMaxDays()) {
                throw PostingException::because('backdate_too_old', "Задним числом — не раньше чем на {$settings->backdateMaxDays()} дн.");
            }
            if (trim((string) $doc->reason_text) === '') {
                throw PostingException::because('backdate_reason', 'Укажите причину проведения задним числом');
            }
        }
    }

    private function fixExchangeRate(Document $doc, PostingContext $ctx, DocumentHandler $handler): void
    {
        if ($doc->exchange_rate_manual) {
            if (! $ctx->isOwner()) {
                throw PostingException::because('manual_rate', 'Ручной курс в документе может поставить только владелец');
            }
            if ($doc->exchange_rate === null) {
                throw PostingException::because('no_rate', 'Укажите курс документа');
            }

            return;
        }
        $current = $this->rates->current($ctx->now);
        $doc->exchange_rate = $current?->toString();
        if ($current === null && $handler->requiresExchangeRate($doc)) {
            throw PostingException::because('no_rate', 'Не задан курс $. Установите курс в шапке системы');
        }
    }
}
