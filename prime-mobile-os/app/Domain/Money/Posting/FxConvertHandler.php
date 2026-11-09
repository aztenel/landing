<?php

namespace App\Domain\Money\Posting;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\FxConversion;

/**
 * Конвертация валюты (TZ 02 §3.4, №40): −from account, +to account, both valued at the document rate.
 * If the exchange was at another rate, the actual amounts are entered; the ₸ difference
 * (Σ amount_kzt of the two entries) is the exchange difference, stored in fx_conversions.fx_diff_kzt
 * (− loss / + gain) for the «Курсовая разница» line of the profit report. No fake third money entry is written:
 * account balances must equal real money.
 */
class FxConvertHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly MoneyLedger $money) {}

    public function type(): DocumentType
    {
        return DocumentType::FxConvert;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        return true;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $fx = $this->conversion($document);
        $from = $this->money->account((int) $fx->from_account_id);
        $to = $this->money->account((int) $fx->to_account_id);
        if ($from->currency === $to->currency) {
            throw PostingException::because('same_currency', 'Конвертация — между счетами разных валют. Для одной валюты — перевод');
        }
        if ($fx->from_amount <= 0 || $fx->to_amount <= 0) {
            throw PostingException::because('bad_amount', 'Суммы конвертации должны быть больше нуля');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $fx = $this->conversion($document);

        return (new LockPlan)->account((int) $fx->from_account_id)->account((int) $fx->to_account_id);
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $ctx->authorize('money.transfer');
        $fx = $this->conversion($document);
        $out = $this->money->record($ctx, (int) $fx->from_account_id, -(int) $fx->from_amount, ArticleCode::FxConvert);
        $in = $this->money->record($ctx, (int) $fx->to_account_id, (int) $fx->to_amount, ArticleCode::FxConvert);
        $fx->forceFill(['fx_diff_kzt' => (int) $out->amount_kzt + (int) $in->amount_kzt])->save();
    }

    private function conversion(Document $document): FxConversion
    {
        return FxConversion::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных конвертации');
    }
}
