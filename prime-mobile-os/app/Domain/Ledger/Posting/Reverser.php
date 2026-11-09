<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Inventory\Location;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Events\DocumentReversed;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\DebtEntry;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Services\AccountingPeriods;
use App\Domain\Ledger\Services\DocumentNumberAllocator;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Limit;
use App\Models\User;
use App\Support\Idempotency\Idempotency;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Сторно (TZ 02 §2.1, §9.2, §10.3): a new posted document of type `reversal` with inverse entries for EVERY journal
 * row of the original; the original becomes `reversed`. Rules:
 *  - only a posted document; a reversal itself cannot be reversed; one reversal per document (unique index);
 *  - reason from ReversalReason; «other» needs ≥ 5 characters;
 *  - right doc.reverse with limit L18 (days since posting);
 *  - forbidden if a unit of the document changed afterwards (its last movement belongs to another document) or
 *    the handler reports dependants (assertReversible), money/bonus balances would go below zero, etc.;
 *  - entries get the business date of the original if its period is open, otherwise today (closed period).
 */
class Reverser
{
    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly LockManager $locks,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AccountingPeriods $periods,
        private readonly AccessChecker $access,
        private readonly ApprovalGate $approvals,
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
        private readonly BonusLedger $bonuses,
    ) {}

    public function reverse(Document $original, ReversalReason|string $reason, ?string $reasonText, User $actor): Document
    {
        $organization = CurrentOrganization::instance()->require();
        $reason = $reason instanceof ReversalReason ? $reason : ReversalReason::from($reason);
        if ($reason === ReversalReason::Other && mb_strlen(trim((string) $reasonText)) < 5) {
            throw PostingException::because('reason_required', 'Опишите причину сторно (не короче 5 символов)');
        }

        return TransactionRetry::run(function () use ($original, $reason, $reasonText, $actor, $organization) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            // lock order step 1 (TZ 02 §12.1): the date of the reversal entries depends on closed_until — a period
            // close must not slip in between (it would otherwise post this reversal into the month being closed)
            DB::table('organizations')->where('id', $organization->id)->lock('for key share')->value('id');

            /** @var Document $orig */
            $orig = Document::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($orig->type === DocumentType::Reversal) {
                throw ReversalBlocked::because('reversal_of_reversal', 'Сторно сторно-документа запрещено. Проведите документ заново');
            }
            if ($orig->status === DocumentStatus::Reversed) {
                $by = Document::query()->find($orig->reversed_by_document_id);
                throw ReversalBlocked::because('already_reversed', "Документ уже сторнирован ({$by?->number})");
            }
            if ($orig->status !== DocumentStatus::Posted) {
                throw ReversalBlocked::because('not_posted', 'Сторнировать можно только проведённый документ');
            }

            $now = CarbonImmutable::now();
            $occurredAt = $this->periods->isOpen(CarbonImmutable::parse($orig->occurred_at)) ? $orig->occurred_at : $now;

            $reversal = new Document;
            $reversal->forceFill([
                'type' => DocumentType::Reversal->value,
                'status' => DocumentStatus::Draft->value,
                'store_id' => $orig->store_id,
                'occurred_at' => $occurredAt,
                'reversal_of_document_id' => $orig->id,
                'reason_code' => $reason->value,
                'reason_text' => $reasonText,
                'exchange_rate' => $orig->exchange_rate,
                'exchange_rate_manual' => true,
                'idempotency_key' => Idempotency::derived('reversal', $orig->id),
                'created_by' => $actor->id,
            ])->save();

            $ctx = new PostingContext($reversal, $actor, $organization, $now, $this->access, $this->approvals, isReversal: true);
            $tz = $organization->timezone; // L18 counts calendar days of the organization, not of UTC
            $days = (int) abs(CarbonImmutable::parse($orig->posted_at)->setTimezone($tz)->startOfDay()->diffInDays($now->setTimezone($tz)->startOfDay()));
            $ctx->authorize('doc.reverse', Limit::ReversalDays, $days, 'reversal');

            $handler = $this->handlers->for($orig->type);
            $handler->assertReversible($orig, $ctx);

            $movements = InventoryMovement::query()->where('document_id', $orig->id)->orderByDesc('id')->get();
            $moneyEntries = MoneyEntry::query()->where('document_id', $orig->id)->orderByDesc('id')->get();
            $debtEntries = DebtEntry::query()->where('document_id', $orig->id)->orderByDesc('id')->get();
            $bonusEntries = BonusEntry::query()->where('document_id', $orig->id)->orderBy('is_source')->orderByDesc('id')->get();

            $plan = $handler->reversalLockPlan($orig);
            foreach ($movements as $m) {
                $m->stock_unit_id
                    ? $plan->unit((int) $m->stock_unit_id)
                    : $plan->accessory((int) $m->accessory_id, Location::fromColumns($m->location_type, (int) $m->location_id));
            }
            $moneyEntries->each(fn (MoneyEntry $e) => $plan->account((int) $e->account_id));
            $debtEntries->each(fn (DebtEntry $e) => $plan->counterparty($e->counterparty_type, (int) $e->counterparty_id, $e->currency));
            $bonusEntries->each(fn (BonusEntry $e) => $plan->bonus((int) $e->customer_id));

            $year = (int) CarbonImmutable::parse($occurredAt)->setTimezone($organization->timezone)->format('Y');
            $ctx->locks = $this->locks->acquire($plan, $organization->id, DocumentType::Reversal->prefix(), $year);

            // rule 2.1 p.4: the unit must not have changed after this document. «Live» movements only: a document
            // that was itself reversed (and its reversal) no longer counts.
            foreach ($ctx->locks->units() as $unit) {
                $last = InventoryMovement::query()
                    ->where('stock_unit_id', $unit->id)
                    ->whereNull('reversal_of_entry_id')
                    ->whereNotExists(fn ($q) => $q->from('inventory_movements as r')->whereColumn('r.reversal_of_entry_id', 'inventory_movements.id'))
                    ->orderByDesc('id')
                    ->first();
                if ($last && (int) $last->document_id !== $orig->id) {
                    $later = Document::query()->find($last->document_id);
                    throw ReversalBlocked::because('later_documents', "Сначала сторнируйте: {$later?->title()} (телефон {$unit->public_code})", [
                        'document_id' => $later?->id,
                    ]);
                }
            }

            foreach ($movements as $m) {
                $this->inventory->reverse($ctx, $m);
            }
            foreach ($moneyEntries as $e) {
                $this->money->reverse($ctx, $e);
            }
            foreach ($debtEntries as $e) {
                $this->debts->reverse($ctx, $e);
            }
            foreach ($bonusEntries as $e) {
                $this->bonuses->reverse($ctx, $e);
            }

            $handler->afterReverse($orig, $ctx);

            $reversal->forceFill([
                'number' => $this->numbers->issue($ctx->locks->counter()),
                'number_year' => $year,
                'status' => DocumentStatus::Posted->value,
                'posted_at' => $now,
                'posted_by' => $actor->id,
            ])->save();
            $orig->forceFill(['status' => DocumentStatus::Reversed->value, 'reversed_by_document_id' => $reversal->id])->save();

            activity('documents')
                ->performedOn($orig)
                ->causedBy($actor)
                ->withProperties([
                    'organization_id' => $organization->id, 'number' => $orig->number, 'reversal_number' => $reversal->number,
                    'reason' => $reason->value, 'reason_text' => $reasonText,
                ])
                ->log('reversed');

            $origId = $orig->id;
            $revId = $reversal->id;
            $type = $orig->type;
            DB::afterCommit(fn () => event(new DocumentReversed($origId, $revId, $organization->id, $type)));

            return $reversal;
        });
    }
}
