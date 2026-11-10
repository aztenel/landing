<?php

namespace App\Domain\Money\Posting;

use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\AccountType;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\CashMove;
use App\Domain\Money\Models\MoneyAccount;

/**
 * «Движение денег» ДДС (TZ 02 §6.2 №35–38, 01 П18; audit №28, docs/audit/decisions.md):
 *   deposit            +amount to an account (owner / admin: money.transfer);
 *   withdrawal         −amount from an account, «Изъятие владельцем» (owner only);
 *   transfer           −amount (and −fee) from one account, +amount to another of the same currency (money.transfer);
 *   collection         store cash −S → «Инкассация в пути» +S (money.collect_send: seller, senior, admin, owner);
 *   collection_accept  «в пути» −S → safe +F (or withdrawal by the owner), S − F on «в пути» as cash difference with the
 *                      sender's name; only the owner (money.collect_receive). «В пути» is 0 afterwards.
 * Rejecting a collection = reversal of the sending document (owner only, and only while not accepted).
 */
class CashMoveHandler extends AbstractDocumentHandler
{
    public const TRANSIT = ['KZT' => 'collection_transit_kzt', 'USD' => 'collection_transit_usd'];

    public function __construct(private readonly MoneyLedger $money) {}

    public function type(): DocumentType
    {
        return DocumentType::CashMove;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $m = $this->move($document);
        if ($m->amount <= 0 || $m->fee_amount < 0) {
            throw PostingException::because('bad_amount', 'Сумма должна быть больше нуля');
        }
        $need = match ($m->kind) {
            'deposit' => [false, true],
            'withdrawal', 'collection' => [true, false],
            'transfer' => [true, true],
            'collection_accept' => [false, false],
            default => throw PostingException::because('bad_kind', 'Неизвестный вид движения денег'),
        };
        if ($need[0] && ! $m->from_account_id || $need[1] && ! $m->to_account_id) {
            throw PostingException::because('account_required', 'Выберите счёт');
        }
        if ($m->kind === 'transfer' && (int) $m->from_account_id === (int) $m->to_account_id) {
            throw PostingException::because('same_account', 'Счета «откуда» и «куда» совпадают');
        }
        if ($m->kind === 'collection_accept' && ! $m->collection_document_id) {
            throw PostingException::because('collection_required', 'Выберите инкассацию, которую принимаете');
        }
        if ($m->kind !== 'transfer' && $m->fee_amount > 0) {
            throw PostingException::because('fee_only_transfer', 'Комиссия бывает только у перевода');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $m = $this->move($document);
        $plan = (new LockPlan)->account($m->from_account_id ? (int) $m->from_account_id : null)->account($m->to_account_id ? (int) $m->to_account_id : null);
        if (in_array($m->kind, ['collection', 'collection_accept'], true)) {
            foreach ([...array_values(self::TRANSIT), 'safe_kzt', 'safe_usd'] as $code) {
                $plan->account(MoneyAccount::query()->where('code', $code)->value('id'));
            }
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $m = $this->move($document);
        $amount = (int) $m->amount;
        $comment = $m->description ? ['comment' => $m->description] : [];

        switch ($m->kind) {
            case 'deposit':
                $ctx->authorize('money.transfer', message: 'Внести деньги может админ или владелец');
                $this->money->record($ctx, (int) $m->to_account_id, $amount, ArticleCode::Deposit, $comment);
                break;

            case 'withdrawal':
                $ctx->requireApprovalUnlessOwner('money.transfer', 'withdrawal', 'Изъятие денег из кассы — только владелец');
                $this->money->record($ctx, (int) $m->from_account_id, -$amount, ArticleCode::Withdrawal, $comment);
                break;

            case 'transfer':
                $ctx->authorize('money.transfer', message: 'Переводить деньги между счетами может админ или владелец');
                $from = $this->money->account((int) $m->from_account_id);
                $to = $this->money->account((int) $m->to_account_id);
                if ($from->currency !== $to->currency) {
                    throw PostingException::because('currency_mismatch', 'Счета в разной валюте — оформите обмен валюты');
                }
                $this->money->record($ctx, $from->id, -$amount, ArticleCode::Transfer, $comment);
                $this->money->record($ctx, $to->id, $amount, ArticleCode::Transfer, $comment);
                if ($m->fee_amount > 0) {
                    $this->money->record($ctx, $from->id, -(int) $m->fee_amount, ArticleCode::BankFee, $comment);
                }
                break;

            case 'collection':
                $ctx->authorize('money.collect_send', message: 'Отправить инкассацию может сотрудник с галочкой «Финансы»');
                $from = $this->money->account((int) $m->from_account_id);
                if ($from->type !== AccountType::Cash) {
                    throw PostingException::because('cash_only', 'Инкассация — только из наличной кассы магазина');
                }
                $transit = $this->transit($from->currency->value);
                $this->money->record($ctx, $from->id, -$amount, ArticleCode::Transfer, $comment);
                $this->money->record($ctx, $transit->id, $amount, ArticleCode::Transfer, $comment);
                $m->forceFill(['to_account_id' => $transit->id, 'status' => 'sent'])->save();
                break;

            case 'collection_accept':
                $ctx->authorize('money.collect_receive', message: 'Инкассацию принимает только владелец');
                $this->accept($document, $m, $ctx, $amount, $comment);
                break;
        }
    }

    private function accept(Document $document, CashMove $m, PostingContext $ctx, int $received, array $comment): void
    {
        $sentDoc = Document::query()->whereKey($m->collection_document_id)->lockForUpdate()->first();
        $sent = $sentDoc ? CashMove::query()->where('document_id', $sentDoc->id)->first() : null;
        if (! $sentDoc || $sentDoc->status !== DocumentStatus::Posted || ! $sent || $sent->kind !== 'collection' || $sent->status !== 'sent') {
            throw PostingException::because('collection_not_pending', 'Эта инкассация уже принята или отменена');
        }
        $transit = $this->money->account((int) $sent->to_account_id);
        $expected = (int) $sent->amount;
        $this->money->record($ctx, $transit->id, -$received, ArticleCode::Transfer, $comment);
        if ($m->target === 'owner') {
            // the owner takes the money: it leaves the firm through the safe of the same currency
            $safe = $this->safe($transit->currency->value);
            $this->money->record($ctx, $safe->id, $received, ArticleCode::Transfer, $comment);
            $this->money->record($ctx, $safe->id, -$received, ArticleCode::Withdrawal, $comment);
        } else {
            $this->money->record($ctx, $m->to_account_id ? (int) $m->to_account_id : $this->safe($transit->currency->value)->id, $received, ArticleCode::Transfer, $comment);
        }
        if ($received !== $expected) {
            // shortage or surplus of the sender, with his name (audit №28): «в пути» ends at 0
            $sender = \App\Models\User::query()->find($sentDoc->posted_by)?->name ?? '';
            $this->money->record($ctx, $transit->id, -($expected - $received), ArticleCode::CashDiff, [
                'comment' => trim(($expected > $received ? 'Недостача инкассации' : 'Излишек инкассации').' — '.$sender.'. '.($m->description ?? '')),
            ]);
        }
        $sent->forceFill(['status' => 'accepted', 'accepted_document_id' => $document->id])->save();
    }

    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        $m = $this->move($document);
        if ($m->kind === 'collection') {
            if ($m->status === 'accepted') {
                throw ReversalBlocked::because('collection_accepted', 'Инкассация уже принята — сначала сторнируйте приём');
            }
            $ctx->authorize('money.collect_receive', message: 'Отклонить инкассацию может только владелец');
        }
    }

    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        $m = $this->move($original);
        if ($m->kind === 'collection_accept') {
            CashMove::query()->where('document_id', $m->collection_document_id)->update(['status' => 'sent', 'accepted_document_id' => null]);
        }
    }

    private function transit(string $currency): MoneyAccount
    {
        return MoneyAccount::query()->where('code', self::TRANSIT[$currency] ?? '')->first()
            ?? throw PostingException::because('no_transit', 'Нет счёта «Инкассация в пути» — обратитесь к интегратору');
    }

    private function safe(string $currency): MoneyAccount
    {
        return MoneyAccount::query()->where('code', $currency === 'USD' ? 'safe_usd' : 'safe_kzt')->first()
            ?? throw PostingException::because('no_safe', 'Нет счёта «Сейф»');
    }

    private function move(Document $document): CashMove
    {
        return CashMove::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных движения денег');
    }
}
