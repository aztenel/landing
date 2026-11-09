<?php

namespace App\Domain\Approvals\Fingerprint;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\Expense;
use App\Domain\Money\Models\ExpensePayment;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\CustomerReturnPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use Illuminate\Support\Facades\DB;

/**
 * Fingerprint of a draft document for approvals. Extractors per document type read only the INPUT of the draft
 * (what the employee entered), never columns the handler computes during posting. Modules register their type:
 *
 *   app(DocumentFingerprints::class)->register(DocumentType::TradeIn, fn (Document $d) => [
 *       'groups' => ['items' => [...], 'payments' => [...]],
 *       'values' => ['buyout' => 15000000],
 *   ]);
 *
 * A type without an extractor gets a generic one: the header (type, store, meta) + every row of every table with a
 * document_id column (minus ids and timestamps). It is strict (any change invalidates), which is safe.
 */
class DocumentFingerprints
{
    /** @var array<string, callable(Document): array{groups: array, values?: array}> */
    private array $extractors = [];

    /** @var list<string>|null */
    private static ?array $detailTables = null;

    public function __construct()
    {
        $this->register(DocumentType::Sale, fn (Document $d) => $this->sale($d));
        $this->register(DocumentType::CustomerReturn, fn (Document $d) => $this->customerReturn($d));
        $this->register(DocumentType::Expense, fn (Document $d) => $this->expense($d));
    }

    /** @param callable(Document): array{groups: array, values?: array} $extractor */
    public function register(DocumentType $type, callable $extractor): void
    {
        $this->extractors[$type->value] = $extractor;
    }

    public function of(Document $document): Fingerprint
    {
        $extractor = $this->extractors[$document->type->value] ?? fn (Document $d) => $this->generic($d);
        $result = $extractor($document);

        return Fingerprint::fromData($result['groups'], $result['values'] ?? []);
    }

    // ------------------------------------------------------------------ core types

    private function sale(Document $d): array
    {
        $sale = Sale::query()->where('document_id', $d->id)->first();
        $lines = SaleLine::query()->where('document_id', $d->id)->orderBy('line_no')->orderBy('id')->get();
        $payments = SalePayment::query()->where('document_id', $d->id)->orderBy('method')->orderBy('amount')->orderBy('id')->get();
        $discount = (int) $lines->sum('line_discount') + (int) ($sale?->check_discount ?? 0);
        $debt = $payments->filter(fn ($p) => $p->method->value === 'debt');
        $methods = $payments->map(fn ($p) => [$p->method->value, $p->account_id])->unique(fn ($m) => implode(':', $m))->sortBy(fn ($m) => implode(':', $m))->values()->all();

        return [
            'groups' => [
                'party' => [$d->store_id, $sale?->seller_id, $sale?->customer_id, $sale?->order_document_id],
                'items' => $lines->map(fn ($l) => [$l->stock_unit_id, $l->accessory_id, (int) $l->qty, (int) $l->price, (bool) $l->is_gift])->all(),
                'discount' => [$lines->map(fn ($l) => (int) $l->line_discount)->all(), (int) ($sale?->check_discount ?? 0)],
                'methods' => $methods,
                'payments' => $payments->map(fn ($p) => [$p->method->value, $p->account_id, (int) $p->amount, $p->debt_due_date?->format('Y-m-d')])->all(),
            ],
            'values' => [
                'discount' => $discount,
                'debt' => (int) $debt->sum('amount'),
                'debt_due' => $debt->map(fn ($p) => $p->debt_due_date?->format('Y-m-d'))->filter()->max(),
                'total' => (int) $lines->sum(fn ($l) => $l->price * $l->qty) - $discount,
            ],
        ];
    }

    private function customerReturn(Document $d): array
    {
        $header = CustomerReturn::query()->where('document_id', $d->id)->first();
        $lines = CustomerReturnLine::query()->where('document_id', $d->id)->orderBy('id')->get();
        $refunds = CustomerReturnPayment::query()->where('document_id', $d->id)->orderBy('id')->get();

        return [
            'groups' => [
                'party' => [$d->store_id, $header?->sale_document_id, $header?->customer_id],
                'items' => $lines->map(fn ($l) => [$l->sale_line_id, (int) $l->qty, $l->amount === null ? null : (int) $l->amount, $l->unit_status_after])->all(),
                'payments' => $refunds->map(fn ($r) => [$r->method, $r->account_id, (int) $r->amount])->all(),
            ],
            'values' => ['refund' => (int) $refunds->sum('amount')],
        ];
    }

    private function expense(Document $d): array
    {
        $expense = Expense::query()->where('document_id', $d->id)->first();
        $payments = ExpensePayment::query()->where('document_id', $d->id)->orderBy('id')->get();
        $kzt = 0;
        foreach ($payments as $p) {
            $kzt += MoneyAccount::query()->whereKey($p->account_id)->value('currency') === 'KZT' ? (int) $p->amount : 0;
        }

        return [
            'groups' => [
                'party' => [$d->store_id, $expense?->article_id, $expense?->counterparty_type, $expense?->counterparty_id, $expense?->description],
                'payments' => [$payments->map(fn ($p) => [$p->account_id, (int) $p->amount])->all(), (int) ($expense?->credit_amount ?? 0), $expense?->due_date],
            ],
            'values' => ['expense' => $kzt + (int) ($expense?->credit_amount ?? 0)],
        ];
    }

    // ------------------------------------------------------------------ generic

    private function generic(Document $d): array
    {
        $groups = ['header' => [$d->type->value, $d->store_id, $d->reversal_of_document_id, $d->meta]];
        foreach (self::detailTables() as $table) {
            $rows = DB::table($table)->where('document_id', $d->id)->orderBy('id')->get()
                ->map(fn ($r) => array_diff_key((array) $r, array_flip(['id', 'organization_id', 'document_id', 'created_at', 'updated_at'])))
                ->all();
            if ($rows) {
                $groups['t:'.$table] = $rows;
            }
        }

        return ['groups' => $groups];
    }

    /** @return list<string> */
    private static function detailTables(): array
    {
        return self::$detailTables ??= array_values(array_diff(
            DB::table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'document_id')->pluck('table_name')->unique()->sort()->values()->all(),
            ['inventory_movements', 'money_entries', 'debt_entries', 'bonus_entries', 'approval_requests', 'app_notifications', 'shifts', 'stock_units'],
        ));
    }
}
