<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\ApprovalKinds;
use App\Domain\Catalog\Models\Accessory;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\AccessoryCost;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\Expense;
use App\Domain\Money\Models\ExpensePayment;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Notifications\Services\Recipients;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\CustomerReturnPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Services\ReturnCalculator;
use App\Domain\Tenancy\Access\Limit;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;

/**
 * Texts of a request for the owner (TZ 03 §6.3, TZ 04 Э28.3): headline for the lock screen (no money),
 * summary (what is asked — goes to push and Telegram, so no customer names / phones / IMEI), context for the card
 * in the system (customer, seller's day), sensitive figures (cost, profit — shown only to those who may see them)
 * and the money size of the request (PIN threshold, decider limits).
 *
 * Modules add their document types:
 *   app(ApprovalSummaries::class)->register(DocumentType::TradeIn, fn (Document $d, array $requirements) => [...]);
 */
class ApprovalSummaries
{
    /** Display priority of the main kind of a request with several requirements. */
    private const PRIORITY = ['below_cost', 'reversal', 'return', 'expense', 'debt_overdue', 'debt', 'discount', 'no_imei_check'];

    /** @var array<string, callable(Document, array): array> */
    private array $describers = [];

    /** @param callable(Document, array $requirements): array{summary?: string, context?: array, sensitive?: array, max_value?: int} $describer */
    public function register(DocumentType $type, callable $describer): void
    {
        $this->describers[$type->value] = $describer;
    }

    public static function mainKind(array $requirements): string
    {
        $kinds = array_map(fn ($r) => $r['type'], $requirements);
        foreach (self::PRIORITY as $kind) {
            if (in_array($kind, $kinds, true)) {
                return $kind;
            }
        }

        return $kinds[0] ?? 'other';
    }

    public static function headline(User $requester, string $kind): string
    {
        return __(':name просит разрешение: :what', ['name' => Recipients::firstName($requester), 'what' => ApprovalKinds::label($kind)]);
    }

    /** @return array{summary: string, context: array, sensitive: array, max_value: int} */
    public function forDocument(Document $document, array $requirements): array
    {
        $result = match (true) {
            isset($this->describers[$document->type->value]) => ($this->describers[$document->type->value])($document, $requirements),
            $document->type === DocumentType::Sale => $this->sale($document, $requirements),
            $document->type === DocumentType::CustomerReturn => $this->customerReturn($document),
            $document->type === DocumentType::Expense => $this->expense($document),
            default => [],
        };

        return [
            'summary' => $result['summary'] ?? $this->fromRequirements($requirements),
            'context' => $result['context'] ?? [],
            'sensitive' => $result['sensitive'] ?? [],
            'max_value' => (int) ($result['max_value'] ?? $this->maxFromRequirements($requirements)),
            'decider_checks' => $result['decider_checks'] ?? [],
        ];
    }

    /** @return array{summary: string, context: array, sensitive: array, max_value: int} */
    public function forReversal(Document $original): array
    {
        $amount = match ($original->type) {
            DocumentType::Sale => (int) Sale::query()->where('document_id', $original->id)->value('total'),
            DocumentType::CustomerReturn => (int) CustomerReturn::query()->where('document_id', $original->id)->value('total'),
            DocumentType::Expense => (int) Expense::query()->where('document_id', $original->id)->value('total_kzt'),
            default => (int) abs((int) $original->moneyEntries()->where('amount_kzt', '<', 0)->sum('amount_kzt')),
        };
        $tz = CurrentOrganization::instance()->require()->timezone;

        return [
            'summary' => trim(__('Сторно: :title от :date', ['title' => $original->title(), 'date' => $original->occurred_at->setTimezone($tz)->format('d.m.Y')])
                .($amount > 0 ? ' · '.Money::kztMinor($amount)->format() : '')),
            'context' => ['document_id' => $original->id, 'number' => $original->number],
            'sensitive' => [],
            'max_value' => $amount,
            // limit L18 of the decider (admin: 30 days) — the requester's «только заявкой» carries no limit
            'decider_checks' => [['doc.reverse', 'L18', (int) abs($original->posted_at->setTimezone($tz)->startOfDay()->diffInDays(now()->setTimezone($tz)->startOfDay()))]],
        ];
    }

    // ------------------------------------------------------------------ sale

    private function sale(Document $d, array $requirements): array
    {
        $sale = Sale::query()->where('document_id', $d->id)->first();
        $lines = SaleLine::query()->where('document_id', $d->id)->orderBy('line_no')->get();
        $payments = SalePayment::query()->where('document_id', $d->id)->get();
        $subtotal = (int) $lines->sum(fn ($l) => $l->price * $l->qty);
        $discount = (int) $lines->sum('line_discount') + (int) ($sale?->check_discount ?? 0);
        $total = $subtotal - $discount;
        $debt = (int) $payments->filter(fn ($p) => $p->method->value === 'debt')->sum('amount');
        $due = $payments->filter(fn ($p) => $p->method->value === 'debt')->map(fn ($p) => $p->debt_due_date)->filter()->max();
        $items = $this->saleItems($lines);
        $kinds = array_map(fn ($r) => $r['type'], $requirements);

        $parts = [];
        if (in_array('discount', $kinds, true)) {
            $parts[] = __('Скидка :amount на :items (:from → :to)', [
                'amount' => Money::kztMinor($discount)->format(), 'items' => $items,
                'from' => Money::kztMinor($subtotal)->format(), 'to' => Money::kztMinor($total)->format(),
            ]);
        }
        if (in_array('below_cost', $kinds, true)) {
            $parts[] = __('Продажа ниже себестоимости: :items, итог :total', ['items' => $items, 'total' => Money::kztMinor($total)->format()]);
        }
        foreach (['debt', 'debt_overdue'] as $k) {
            if (in_array($k, $kinds, true)) {
                $after = collect($requirements)->first(fn ($r) => $r['type'] === $k && $r['limit'] === 'L4')['actual_value'] ?? null;
                $parts[] = trim(__('Долг :debt до :due', ['debt' => Money::kztMinor($debt)->format(), 'due' => $due?->format('d.m.Y') ?? '—'])
                    .($after !== null ? ' · '.__('общий долг клиента станет :after', ['after' => Money::kztMinor((int) $after)->format()]) : '')
                    .($k === 'debt_overdue' ? ' · '.__('у клиента есть просроченный долг') : ''));
                break;
            }
        }
        if (in_array('no_imei_check', $kinds, true)) {
            $parts[] = __('Продажа без отметки госбазы: :items', ['items' => $items]);
        }
        if (! $parts) {
            $parts[] = $this->fromRequirements($requirements);
        }

        $cost = 0;
        foreach ($lines as $l) {
            $cost += $l->stock_unit_id
                ? (int) StockUnit::query()->whereKey($l->stock_unit_id)->value('cost_kzt')
                : (int) AccessoryCost::query()->where('accessory_id', $l->accessory_id)->value('avg_cost_kzt') * (int) $l->qty;
        }
        $profit = $total - $cost;

        return [
            'summary' => implode(' · ', $parts),
            'context' => array_filter([
                'check' => ['subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'debt' => $debt],
                'customer' => $sale?->customer_id ? $this->customerContext((int) $sale->customer_id) : null,
                'seller_discounts_today' => $sale ? $this->sellerDiscountsToday((int) $sale->seller_id) : null,
            ], fn ($v) => $v !== null),
            'sensitive' => [
                'cost' => $cost,
                'profit' => $profit,
                'margin_bp' => $total > 0 ? intdiv($profit * 10000, $total) : 0,  // basis points: 1090 = 10,90 %
            ],
            'max_value' => max(
                in_array('discount', $kinds, true) ? $discount : 0,
                array_intersect(['debt', 'debt_overdue'], $kinds) ? $debt : 0,
                array_intersect(['below_cost', 'no_imei_check'], $kinds) ? $total : 0,
            ),
        ];
    }

    private function saleItems($lines): string
    {
        $out = [];
        foreach ($lines as $l) {
            if ($l->stock_unit_id) {
                $unit = StockUnit::query()->with('variant')->find($l->stock_unit_id);
                $out[] = trim(($unit?->variant?->title() ?? __('Телефон')).' ('.($unit?->public_code ?? '—').')');
            } else {
                $name = Accessory::query()->whereKey($l->accessory_id)->value('name_ru') ?? __('Аксессуар');
                $out[] = $l->qty > 1 ? "{$name} × {$l->qty}" : $name;
            }
        }
        if (count($out) > 3) {
            return implode(', ', array_slice($out, 0, 3)).' '.__('и ещё :n', ['n' => count($out) - 3]);
        }

        return implode(', ', $out);
    }

    /** «Клиент Асхат, 3 покупки на 812 000 ₸, долгов нет» — in-app card only (TZ 03 §6.3). */
    private function customerContext(int $customerId): array
    {
        $customer = Customer::query()->find($customerId);
        $sales = Sale::query()->join('documents', 'documents.id', '=', 'sales.document_id')
            ->where('documents.status', DocumentStatus::Posted->value)->where('sales.customer_id', $customerId);
        $balance = CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('counterparty_id', $customerId)->where('currency', 'KZT')->first();
        $tz = CurrentOrganization::instance()->require()->timezone;

        return [
            'name' => $customer?->name,
            'purchases' => (int) (clone $sales)->count(),
            'purchases_total' => (int) (clone $sales)->sum('sales.total'),
            'debt' => max(0, (int) ($balance?->balance ?? 0)),
            'debt_overdue' => $balance && (int) $balance->balance > 0 && $balance->nearest_due_date
                && CarbonImmutable::parse($balance->nearest_due_date, $tz)->lt(CarbonImmutable::now($tz)->startOfDay()),
        ];
    }

    private function sellerDiscountsToday(int $sellerId): int
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $start = CarbonImmutable::now($tz)->startOfDay();

        return (int) Sale::query()->join('documents', 'documents.id', '=', 'sales.document_id')
            ->where('documents.status', DocumentStatus::Posted->value)
            ->where('sales.seller_id', $sellerId)
            ->where('documents.occurred_at', '>=', $start->utc())
            ->sum('sales.discount_total');
    }

    // ------------------------------------------------------------------ return, expense

    private function customerReturn(Document $d): array
    {
        $header = CustomerReturn::query()->where('document_id', $d->id)->first();
        $lines = CustomerReturnLine::query()->where('document_id', $d->id)->get();
        $refund = (int) CustomerReturnPayment::query()->where('document_id', $d->id)->sum('amount');
        $saleDoc = $header ? Document::query()->find($header->sale_document_id) : null;
        $saleLines = SaleLine::query()->whereIn('id', $lines->pluck('sale_line_id'))->get()->keyBy('id');
        $items = $this->saleItems($lines->map(fn ($l) => tap(clone $saleLines[$l->sale_line_id], fn ($s) => $s->qty = $l->qty)));
        $days = $saleDoc ? (int) abs($saleDoc->occurred_at->startOfDay()->diffInDays(now()->startOfDay())) : null;
        $calculator = app(ReturnCalculator::class);
        $phones = 0;
        $accessories = 0;
        foreach ($lines as $l) {
            $amount = $calculator->lineAmount($saleLines[$l->sale_line_id], (int) $l->qty, $l->amount === null ? null : (int) $l->amount);
            $saleLines[$l->sale_line_id]->stock_unit_id ? $phones += $amount : $accessories += $amount;
        }
        $checks = [];
        if ($phones > 0) {
            $checks[] = ['return.phone', 'L8', $phones];
            $checks[] = ['return.phone', 'L9', (int) $days];
        }
        if ($accessories > 0 || $phones === 0) {
            $checks[] = ['return.accessory', 'L7', $accessories];
            $checks[] = ['return.accessory', 'L9', (int) $days];
        }

        return [
            'summary' => __('Возврат :amount: :items по чеку :number, :days дн. после покупки', [
                'amount' => Money::kztMinor($refund)->format(), 'items' => $items, 'number' => $saleDoc?->number ?? '—', 'days' => $days ?? '—',
            ]),
            'context' => ['sale_document_id' => $saleDoc?->id, 'days_since_sale' => $days],
            'max_value' => max($refund, $phones + $accessories),
            'decider_checks' => $checks,
        ];
    }

    private function expense(Document $d): array
    {
        $expense = Expense::query()->where('document_id', $d->id)->first();
        $amount = (int) ExpensePayment::query()->where('document_id', $d->id)->sum('amount') + (int) ($expense?->credit_amount ?? 0);
        $article = $expense ? MoneyArticle::query()->whereKey($expense->article_id)->value('name_ru') : null;

        return [
            'summary' => __('Расход «:article» :amount', ['article' => $article ?? '—', 'amount' => Money::kztMinor($amount)->format()])
                .($expense?->description ? ' · '.mb_substr((string) $expense->description, 0, 80) : ''),
            'max_value' => $amount,
        ];
    }

    // ------------------------------------------------------------------ generic

    private function fromRequirements(array $requirements): string
    {
        $messages = array_values(array_unique(array_filter(array_map(fn ($r) => $r['message'] ?? '', $requirements))));

        return $messages ? implode(' · ', $messages) : __('Нужно разрешение владельца');
    }

    private function maxFromRequirements(array $requirements): int
    {
        $max = 0;
        foreach ($requirements as $r) {
            if ($r['limit'] && Limit::from($r['limit'])->unit() === 'money') {
                $max = max($max, (int) $r['actual_value']);
            }
        }

        return $max;
    }
}
