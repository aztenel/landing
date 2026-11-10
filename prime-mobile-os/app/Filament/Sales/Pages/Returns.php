<?php

namespace App\Filament\Sales\Pages;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Catalog\Models\Accessory;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Pos\Services\CartService;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Services\Exchange;
use App\Domain\Sales\Services\ReturnCalculator;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Э5 «Возврат и обмен» (/returns): wizard in 4 steps — find the sale, what comes back, money, summary. Amounts are
 * computed on the server (ReturnCalculator); the person chooses only lines, quantities, the state of the phone and the
 * refund methods. A seller sends a request to the owner, an admin / owner posts. «Обмен»: the return and a new sale
 * in one operation (Exchange).
 */
class Returns extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['return.phone', 'return.accessory'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'repeat';

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'returns';

    protected static string $view = 'sales.pages.returns';

    public int $step = 1;

    public string $find = '';

    public ?int $saleId = null;

    /** @var array<int, array{on: bool, qty: int, status: string, reason: string, icloud: bool}> sale line id => choice */
    public array $pick = [];

    public bool $exchange = false;

    /** @var list<array{kind: string, id: int, qty: int}> new goods of the exchange */
    public array $take = [];

    public string $takeCode = '';

    /** @var list<array{method: string, amount: string}> refund rows in tenge */
    public array $refunds = [];

    public string $extraMethod = 'cash';

    public string $comment = '';

    public ?int $returnDraftId = null;

    public ?int $saleDraftId = null;

    public ?int $requestId = null;

    public ?int $doneId = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        // a seller may prepare the return and send a request (permission «только заявкой»)
        $user = auth()->user();

        return $user && collect(['return.phone', 'return.accessory'])->contains(fn ($p) => ! Access::instance()->decision($p, $user)->isDenied());
    }

    public static function getNavigationLabel(): string
    {
        return __('Возврат');
    }

    public function getTitle(): string
    {
        return __('Возврат и обмен');
    }

    public function mount(): void
    {
        if ($id = request()->integer('sale')) {
            $this->chooseSale($id);
        }
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'pick') || str_starts_with($name, 'take') || str_starts_with($name, 'refunds') || $name === 'exchange' || $name === 'extraMethod') {
            $this->returnDraftId = $this->saleDraftId = $this->requestId = null; // a request covers exactly what was asked
        }
    }

    // ------------------------------------------------------------------ step 1

    /** @return Collection<int, Document> */
    public function found(): Collection
    {
        $q = trim($this->find);
        if (mb_strlen($q) < 3) {
            return collect();
        }
        $digits = preg_replace('/\D/', '', $q);
        $base = Sales::visible(auth()->user())->where('status', 'posted')->where('posted_at', '>=', now()->subDays(90));

        return $base->where(function ($s) use ($q, $digits) {
            $s->where('number', 'ilike', '%'.addcslashes($q, '%_').'%');
            if (strlen($digits) >= 6) {
                $s->orWhereIn('id', SaleLine::query()->whereIn('stock_unit_id', StockUnit::query()->where('imei1', $digits)->orWhere('imei2', $digits)->orWhere('imei1', 'like', '%'.$digits)->select('id'))->select('document_id'))
                    ->orWhereIn('id', Sale::query()->whereIn('customer_id', Customer::query()->where('phone', 'like', '%'.$digits.'%')->select('id'))->select('document_id'));
            }
        })->latest('posted_at')->limit(10)->get();
    }

    public function chooseSale(int $id): void
    {
        $doc = Sales::visible(auth()->user())->where('status', 'posted')->findOrFail($id);
        $this->saleId = $doc->id;
        $this->pick = [];
        foreach (SaleLine::query()->where('document_id', $doc->id)->orderBy('line_no')->get() as $l) {
            $left = (int) $l->qty - (int) $l->returned_qty;
            $this->pick[$l->id] = ['on' => false, 'qty' => $left, 'status' => 'in_stock', 'reason' => $l->stock_unit_id ? 'defect' : 'not_fit', 'icloud' => false];
        }
        if (collect($this->pick)->every(fn ($p) => $p['qty'] === 0)) {
            $this->error = __('По чеку :n всё возвращено', ['n' => $doc->number]);

            return;
        }
        $this->step = 2;
        $this->error = null;
    }

    // ------------------------------------------------------------------ step 2 → 3

    public function toMoney(): void
    {
        $this->error = null;
        $chosen = collect($this->pick)->filter(fn ($p) => $p['on']);
        if ($chosen->isEmpty()) {
            $this->error = __('Отметьте, что возвращают');

            return;
        }
        foreach ($chosen as $lineId => $p) {
            $line = SaleLine::query()->findOrFail($lineId);
            if ($line->stock_unit_id && $p['status'] === 'in_stock' && ! $p['icloud']) {
                $this->error = __('Пусть клиент выйдет из iCloud — без этого телефон не продать снова. Или выберите «На проверку»');

                return;
            }
        }
        $this->refunds = $this->defaultRefunds();
        $this->step = 3;
    }

    /** Preview of the money, as the core will compute it. @return array{total: int, restore: int, cancel: int, credit: int, to_debt: int, refund: int, take: int} */
    public function quote(): array
    {
        $calc = app(ReturnCalculator::class);
        $total = $restore = $cancel = $credit = 0;
        foreach (collect($this->pick)->filter(fn ($p) => $p['on']) as $lineId => $p) {
            $line = SaleLine::query()->find($lineId);
            if (! $line || $line->document_id !== $this->saleId) {
                continue; // a forged id of another sale line is ignored
            }
            $qty = max(1, min((int) $p['qty'], (int) $line->qty - (int) $line->returned_qty));
            $total += $calc->lineAmount($line, $qty);
            $restore += $calc->bonusesToRestore($line, $qty);
            $cancel += $calc->bonusesToCancel($line, $qty);
            $credit += $calc->creditPart($line, $qty);
        }
        $sale = Sale::query()->where('document_id', $this->saleId)->first();
        $toDebt = 0;
        if ($sale?->customer_id && $credit > 0) {
            $owed = (int) CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('counterparty_id', $sale->customer_id)->where('currency', 'KZT')->value('balance');
            $toDebt = min($credit, max(0, $owed));
        }
        $withheld = 0;
        if ($sale?->customer_id && $cancel > 0) {
            $have = (int) app(BonusLedger::class)->sources((int) $sale->customer_id)->sum('remaining') + $restore;
            $withheld = max(0, $cancel - $have) * Money::TENGE;
        }

        return ['total' => $total, 'restore' => $restore, 'cancel' => $cancel, 'credit' => $credit, 'to_debt' => $toDebt,
            'withheld' => $withheld, 'refund' => $total - $restore * Money::TENGE - $withheld, 'take' => $this->takeTotal()];
    }

    /** Rule of П11.1 p.6 / audit №26: debt first, then the money methods of the sale (non-cash before cash). */
    protected function defaultRefunds(): array
    {
        $q = $this->quote();
        $left = $q['refund'];
        $rows = [];
        if ($q['to_debt'] > 0) {
            $rows[] = ['method' => 'debt', 'amount' => (string) intdiv(min($left, $q['to_debt']), Money::TENGE)];
            $left -= min($left, $q['to_debt']);
        }
        if ($this->exchange && $left > 0) {
            $offset = min($left, $q['take']);
            if ($offset > 0) {
                $rows[] = ['method' => 'exchange_offset', 'amount' => (string) intdiv($offset, Money::TENGE)];
                $left -= $offset;
            }
        }
        if ($left > 0) {
            $methods = SalePayment::query()->where('document_id', $this->saleId)->get()->map(fn ($p) => $p->method)->filter(fn ($m) => $m->isMoney());
            $method = $methods->first(fn ($m) => ! $m->isCash()) ?? $methods->first() ?? PaymentMethodCode::Cash;
            $rows[] = ['method' => $method->value, 'amount' => (string) intdiv($left, Money::TENGE)];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ exchange: new goods

    public function addTake(): void
    {
        $code = trim($this->takeCode);
        $this->takeCode = '';
        $unit = StockUnit::query()->where('public_code', mb_strtoupper($code))->first() ?? StockUnit::findByIdentifier($code);
        $store = (int) Document::query()->whereKey($this->saleId)->value('store_id');
        if ($unit && $unit->isInStore($store) && in_array($unit->status?->value, ['in_stock'], true)) {
            $this->take[] = ['kind' => 'unit', 'id' => $unit->id, 'qty' => 1];

            return;
        }
        $a = Accessory::query()->where('is_active', true)->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        $a ? $this->take[] = ['kind' => 'accessory', 'id' => $a->id, 'qty' => 1] : $this->error = __('Не нашли «:c» в наличии этого магазина', ['c' => $code]);
    }

    public function dropTake(int $i): void
    {
        unset($this->take[$i]);
        $this->take = array_values($this->take);
    }

    public function takeTotal(): int
    {
        $cart = app(CartService::class);
        $sum = 0;
        foreach ($this->take as $t) {
            $sum += $t['kind'] === 'unit'
                ? (int) $cart->unitPrice(StockUnit::query()->findOrFail($t['id']))
                : (int) $cart->accessoryPrice((int) $t['id']) * max(1, (int) $t['qty']);
        }

        return $sum;
    }

    // ------------------------------------------------------------------ step 4: post or request

    protected function drafts(): array
    {
        if ($this->returnDraftId && ($r = Document::query()->find($this->returnDraftId)) && $r->isDraft()) {
            return [$r, $this->saleDraftId ? Document::query()->find($this->saleDraftId) : null];
        }
        $sale = Sales::visible(auth()->user())->where('status', 'posted')->findOrFail($this->saleId);
        $d = CustomerReturnDraft::make($sale)->author(auth()->user());
        foreach (collect($this->pick)->filter(fn ($p) => $p['on']) as $lineId => $p) {
            $line = SaleLine::query()->where('document_id', $sale->id)->findOrFail($lineId);
            $d->line($line, max(1, (int) $p['qty']), $line->stock_unit_id ? $p['status'] : 'in_stock', reason: $p['reason'] ?? null);
        }
        foreach ($this->refunds as $r) {
            $amount = (int) preg_replace('/\D/', '', (string) $r['amount']);
            if ($amount > 0) {
                $d->refund($r['method'], Money::kzt($amount));
            }
        }
        $d->comment(trim($this->comment) ?: null);
        $return = $d->save();
        $newSale = null;
        if ($this->exchange && $this->take !== []) {
            $saleHeader = Sale::query()->where('document_id', $sale->id)->first();
            $offset = (int) collect($this->refunds)->where('method', 'exchange_offset')->sum(fn ($r) => (int) preg_replace('/\D/', '', (string) $r['amount']));
            $cart = app(CartService::class);
            $s = SaleDraft::make((int) $sale->store_id, auth()->user())->author(auth()->user());
            if ($saleHeader?->customer_id) {
                $s->customer((int) $saleHeader->customer_id);
            }
            foreach ($this->take as $t) {
                $t['kind'] === 'unit'
                    ? $s->unit((int) $t['id'], Money::kztMinor((int) $cart->unitPrice(StockUnit::query()->findOrFail($t['id']))))
                    : $s->accessory((int) $t['id'], max(1, (int) $t['qty']), Money::kztMinor((int) $cart->accessoryPrice((int) $t['id'])));
            }
            if ($offset > 0) {
                $s->pay('exchange_offset', Money::kzt($offset));
            }
            $extra = intdiv($this->takeTotal(), Money::TENGE) - $offset;
            if ($extra > 0) {
                $s->pay($this->extraMethod, Money::kzt($extra));
            }
            $newSale = $s->save();
        }
        $this->returnDraftId = $return->id;
        $this->saleDraftId = $newSale?->id;

        return [$return, $newSale];
    }

    /**
     * TZ 00-kazakhstan §9 / ТЗ 07 сценарий 33: a phone of proper quality that «не подошёл» — the shop is not obliged to
     * take it back (phones are in the list of non-returnable goods; not checked by a lawyer), only the owner decides.
     */
    public function goodPhoneReturn(): bool
    {
        if (! $this->saleId) {
            return false;
        }
        $phones = SaleLine::query()->where('document_id', $this->saleId)->whereNotNull('stock_unit_id')->pluck('id')->all();

        return collect($this->pick)->contains(fn ($p, $lineId) => ($p['on'] ?? false) && ($p['reason'] ?? '') === 'not_fit' && in_array((int) $lineId, $phones, true));
    }

    public const LAW_HINT = 'Телефон надлежащего качества по закону возвращать не обязаны (не проверено юристом). Вернуть можно только по решению владельца — отправьте заявку';

    public function post(): void
    {
        $this->error = null;
        try {
            [$return, $newSale] = $this->drafts();
            $posted = $newSale
                ? app(Exchange::class)->post($return, $newSale, auth()->user())[0]
                : app(Poster::class)->post($return, auth()->user());
            $this->doneId = $posted->id;
            $this->step = 5;
        } catch (\App\Domain\Tenancy\Access\Exceptions\ApprovalRequired $e) {
            $this->error = $e->getMessage();
            $this->step = 4;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function sendRequest(): void
    {
        $this->error = null;
        try {
            [$return] = $this->drafts();
            $reasons = ['defect' => 'брак', 'not_fit' => 'не подошёл', 'online' => 'онлайн-покупка', 'seller_error' => 'ошибка продавца', 'owner' => 'по решению владельца'];
            $why = trim($this->comment) ?: 'Возврат: '.collect($this->pick)->filter(fn ($p) => $p['on'])->map(fn ($p) => $reasons[$p['reason']] ?? $p['reason'])->unique()->implode(', ');
            $this->requestId = app(ApprovalService::class)->requestForDocument($return, auth()->user(), $why)->id;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function pollRequest(): void
    {
        $r = $this->requestId ? ApprovalRequest::query()->find($this->requestId) : null;
        if ($r && $r->status === ApprovalStatus::Approved) {
            $this->post();
        }
    }

    public function restart(): void
    {
        $this->reset(['step', 'find', 'saleId', 'pick', 'exchange', 'take', 'refunds', 'comment', 'returnDraftId', 'saleDraftId', 'requestId', 'doneId', 'error']);
    }

    protected function getViewData(): array
    {
        $sale = $this->saleId ? Document::query()->find($this->saleId) : null;
        $tz = CurrentOrganization::instance()->require()->timezone;
        $cart = app(CartService::class);

        return [
            'found' => $this->step === 1 ? $this->found() : collect(),
            'sale' => $sale,
            'header' => $sale ? Sale::query()->where('document_id', $sale->id)->first() : null,
            'lines' => $sale ? SaleLine::query()->where('document_id', $sale->id)->orderBy('line_no')->get() : collect(),
            'days' => $sale?->posted_at ? (int) $sale->posted_at->setTimezone($tz)->startOfDay()->diffInDays(now($tz)->startOfDay()) : null,
            'q' => $this->saleId && $this->step >= 2 ? $this->quote() : null,
            'takeRows' => collect($this->take)->map(fn ($t, $i) => $t + ['i' => $i, 'title' => $t['kind'] === 'unit'
                ? (StockUnit::query()->find($t['id'])?->variant?->title() ?? '') : (Accessory::query()->find($t['id'])?->name_ru ?? ''),
                'price' => $t['kind'] === 'unit' ? (int) $cart->unitPrice(StockUnit::query()->findOrFail($t['id'])) : (int) $cart->accessoryPrice((int) $t['id'])]),
            'request' => $this->requestId ? ApprovalRequest::query()->find($this->requestId) : null,
            'done' => $this->doneId ? Document::query()->find($this->doneId) : null,
            'canPost' => Access::allows('return.phone') || Access::allows('return.accessory'),
            'lawHint' => $this->goodPhoneReturn(),
            'recent' => CustomerReturn::query()->whereIn('document_id', Document::query()->where('type', 'customer_return')->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->select('id'))->latest('id')->limit(10)->get(),
            'tz' => $tz,
        ];
    }
}
