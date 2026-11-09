<?php

namespace App\Domain\Money\Drafts;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\Expense;
use App\Domain\Money\Models\ExpensePayment;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 *   ExpenseDraft::make(ArticleCode::Courier, $store)->pay($cashKzt, Money::kzt(3000))->description('Такси до сервиса')->post($seller);
 */
final class ExpenseDraft extends DraftBuilder
{
    private int $articleId;

    private ?string $description = null;

    private ?int $supplierId = null;

    private int $creditAmount = 0;

    private ?string $dueDate = null;

    /** @var list<array{account_id: int, amount: int}> */
    private array $payments = [];

    public static function make(ArticleCode|MoneyArticle|string $article, Store|int|null $store = null): self
    {
        $draft = new self;
        $draft->articleId = $article instanceof MoneyArticle ? $article->id : MoneyArticle::byCode($article)->id;
        $draft->storeId = self::storeId($store);

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::Expense;
    }

    public function pay(MoneyAccount|int $account, Money $amount): self
    {
        $this->payments[] = ['account_id' => $account instanceof MoneyAccount ? $account->id : $account, 'amount' => $amount->amount];

        return $this;
    }

    public function onCredit(Supplier|int $supplier, Money $amount, CarbonInterface $dueDate): self
    {
        $this->supplierId = $supplier instanceof Supplier ? $supplier->id : $supplier;
        $this->creditAmount = self::kzt($amount);
        $this->dueDate = $dueDate->format('Y-m-d');

        return $this;
    }

    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    protected function writeDetails(Document $document): void
    {
        Expense::query()->create([
            'document_id' => $document->id, 'article_id' => $this->articleId, 'description' => $this->description,
            'counterparty_type' => $this->supplierId ? CounterpartyType::Supplier->value : null,
            'counterparty_id' => $this->supplierId, 'credit_amount' => $this->creditAmount, 'due_date' => $this->dueDate,
        ]);
        foreach ($this->payments as $payment) {
            ExpensePayment::query()->create(['document_id' => $document->id] + $payment);
        }
    }
}
