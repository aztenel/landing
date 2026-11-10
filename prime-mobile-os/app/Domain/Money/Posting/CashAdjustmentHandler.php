<?php

namespace App\Domain\Money\Posting;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\ShiftRequirement;
use App\Domain\Money\Enums\AccountType;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\CashMove;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Tenancy\Access\Limit;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * «Расхождение кассы» РКС (TZ 02 §8, audit №57): ± on ONE cash register by the article «Недостача/излишек кассы»,
 * comment required. From a shift count (shift_phase open | close) the right is shift.open_close — the count itself is
 * the seller's job; a manual correction outside the count is money.adjust with limit L16 (admin up to 5 000 ₸ by
 * default, owner without limit). A difference above the firm's threshold (cash.discrepancy_threshold, $ — by the rate)
 * notifies the owner; the document is posted anyway, the money is already not there.
 */
class CashAdjustmentHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly MoneyLedger $money, private readonly Notifier $notifier) {}

    public function type(): DocumentType
    {
        return DocumentType::CashAdjustment;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return ShiftRequirement::Never; // the count at opening runs before trading, at closing — inside the close
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $m = CashMove::query()->where('document_id', $document->id)->first();

        return $m && MoneyAccount::query()->whereKey($m->to_account_id)->value('currency') === 'USD';
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $m = $this->move($document);
        if ($m->kind !== 'adjustment' || (int) $m->amount === 0 || ! $m->to_account_id) {
            throw PostingException::because('bad_adjustment', 'Расхождение кассы: укажите кассу и разницу');
        }
        if (trim((string) $m->description) === '') {
            throw PostingException::because('comment_required', 'Напишите, почему не сходится касса');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        return (new LockPlan)->account((int) $this->move($document)->to_account_id);
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $m = $this->move($document);
        $account = $this->money->account((int) $m->to_account_id);
        if ($account->type !== AccountType::Cash) {
            throw PostingException::because('cash_only', 'Расхождение бывает только у наличной кассы');
        }
        $kzt = $this->inKzt($ctx, (int) $m->amount, $account->currency->value);

        if ($m->shift_phase) {
            $ctx->authorize('shift.open_close', message: 'Пересчёт кассы делает сотрудник этого магазина');
            if ((int) $account->store_id !== (int) $document->store_id
                || ! Shift::query()->where('store_id', $document->store_id)->where('status', 'open')->exists()) {
                throw PostingException::because('shift_count_mismatch', 'Касса не этого магазина или смена не открыта');
            }
        } else {
            $ctx->authorize('money.adjust', Limit::CashAdjust, abs($kzt), message: 'Корректировка кассы — по лимиту роли');
        }

        $this->money->record($ctx, $account->id, (int) $m->amount, ArticleCode::CashDiff, ['comment' => $m->description]);

        $threshold = (int) $ctx->settings()->get('cash.discrepancy_threshold');
        if (abs($kzt) > $threshold) {
            $who = $ctx->actor->name;
            $money = ($m->amount > 0 ? '+' : '').Money::of((int) $m->amount, $account->currency->value)->format();
            $where = ['open' => __('при открытии смены'), 'close' => __('при закрытии смены')][$m->shift_phase] ?? __('корректировка');
            $this->notifier->toOwners(
                NotificationType::ShiftDiscrepancy,
                __('Касса не сходится: :money (:who)', ['money' => $money, 'who' => $who]),
                $account->name.' · '.$where.' · '.$m->description,
                ['document_id' => $document->id, 'data' => ['store_id' => $document->store_id, 'account_id' => $account->id]],
            );
        }
    }

    /** Signed amount in tiyn; $ by the document rate (without a rate a USD difference counts as above any limit). */
    private function inKzt(PostingContext $ctx, int $amount, string $currency): int
    {
        if ($currency === 'KZT') {
            return $amount;
        }
        $rate = $ctx->rate();

        return $rate ? Money::usdMinor($amount)->convert(Currency::KZT, $rate)->amount : PHP_INT_MAX * ($amount <=> 0);
    }

    private function move(Document $document): CashMove
    {
        return CashMove::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных расхождения кассы');
    }
}
