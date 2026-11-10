<?php

namespace App\Http\Controllers\Printing;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Printing\Printing;
use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\CustomerReturnPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Services\SiteSettings;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Warehouse\Models\WarrantyCase;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Print forms Ф1–Ф12 (TZ 06 §6–§9), way 1 — HTML of the exact size and the browser print window. A document prints
 * only posted (the trade-in act has its own draft print, Ф5). A second print of the same document is «ДУБЛИКАТ» and
 * needs sale.reprint; every print is in the activity log.
 */
class PrintController
{
    public function __construct(private readonly AccessChecker $access) {}

    private function org()
    {
        return CurrentOrganization::instance()->require();
    }

    private function storeAllowed(?int $storeId): bool
    {
        $u = auth()->user();

        return $this->access->isOwner($u) || $storeId === null || (bool) $this->access->membership($u)?->allowsStore($storeId);
    }

    private function posted(int $id, array $types): Document
    {
        $doc = Document::query()->whereKey($id)->whereIn('type', $types)->firstOrFail();
        abort_unless(in_array($doc->status?->value, ['posted', 'reversed'], true), 422, __('Печатается только проведённый документ'));
        abort_unless($this->storeAllowed($doc->store_id ? (int) $doc->store_id : null), 403);

        return $doc;
    }

    private function duplicate(string $form, Document $doc): bool
    {
        $dup = Printing::logAndIsDuplicate($form, Document::class, $doc->id);
        abort_if($dup && ! Access::allows('sale.reprint') && ! Access::isOwner(), 403, __('Повторная печать — по праву «Повторная печать чека»'));

        return $dup;
    }

    private function common(): array
    {
        $org = $this->org();

        return ['org' => $org, 'tz' => $org->timezone, 'brand' => $org->settings()->get('brand.display_name') ?: $org->name];
    }

    /** Lines of a sale with their units / accessories for Ф1, Ф3, Ф11. */
    private function saleLines(Document $doc): Collection
    {
        $lines = SaleLine::query()->where('document_id', $doc->id)->orderBy('line_no')->get();
        $units = StockUnit::query()->with(['variant.productModel', 'variant.memory', 'variant.color'])->whereIn('id', $lines->pluck('stock_unit_id')->filter())->get()->keyBy('id');
        $acc = Accessory::query()->whereIn('id', $lines->pluck('accessory_id')->filter())->get()->keyBy('id');

        return $lines->map(fn ($l) => (object) ['line' => $l, 'unit' => $units[$l->stock_unit_id] ?? null, 'acc' => $acc[$l->accessory_id] ?? null]);
    }

    private function warrantyMonths(?StockUnit $unit, ?Accessory $acc): int
    {
        $s = $this->org()->settings();

        return match (true) {
            $unit !== null => (int) $s->get($unit->condition?->value === 'used' ? 'warranty.used_months' : 'warranty.new_months'),
            $acc !== null => (int) ($acc->warranty_months ?? $s->get('warranty.accessory_months')),
            default => 0,
        };
    }

    // ---- Ф1 Чек продажи (лента 58/80 мм, A4) ----
    public function sale(Request $r, int $document)
    {
        abort_unless(Access::allows('sale.create') || Access::allows('sale.reprint'), 403);
        $doc = $this->posted($document, ['sale']);
        $sale = Sale::query()->where('document_id', $doc->id)->firstOrFail();
        $customer = $sale->customer_id ? Customer::query()->find($sale->customer_id) : null;
        $bonus = $customer ? DB::table('bonus_entries')->where('document_id', $doc->id)->selectRaw('sum(case when amount > 0 then amount else 0 end) plus, sum(case when amount < 0 then -amount else 0 end) minus, min(available_from) av')->first() : null;

        return view('print.sale', $this->common() + [
            'size' => in_array($r->query('w'), ['58', 'a4'], true) ? ($r->query('w') === '58' ? 'tape58' : 'a4') : 'tape80',
            'title' => $doc->number, 'doc' => $doc, 'sale' => $sale, 'lines' => $this->saleLines($doc), 'customer' => $customer,
            'payments' => SalePayment::query()->where('document_id', $doc->id)->orderBy('id')->get(), 'store' => Store::query()->find($doc->store_id),
            'seller' => User::query()->find($sale->seller_id), 'bonus' => $bonus,
            'bonusBalance' => $customer ? DB::table('customer_bonus_balances')->where('customer_id', $customer->id)->value('available') : null,
            'link' => Printing::receiptLink($doc->id), 'duplicate' => $this->duplicate('Ф1', $doc), 'warranty' => fn ($u, $a) => $this->warrantyMonths($u, $a),
        ]);
    }

    // ---- Ф2 Чек возврата ----
    public function returnReceipt(Request $r, int $document)
    {
        abort_unless(Access::allows('sale.create') || Access::allows('return.phone') || Access::allows('return.accessory') || Access::allows('sale.reprint'), 403);
        $doc = $this->posted($document, ['customer_return']);
        $ret = CustomerReturn::query()->where('document_id', $doc->id)->firstOrFail();
        $saleDoc = Document::query()->find($ret->sale_document_id);
        $lines = CustomerReturnLine::query()->where('document_id', $doc->id)->get();
        $saleLines = SaleLine::query()->whereIn('id', $lines->pluck('sale_line_id'))->get()->keyBy('id');
        $units = StockUnit::query()->with('variant')->whereIn('id', $saleLines->pluck('stock_unit_id')->filter())->get()->keyBy('id');
        $acc = Accessory::query()->whereIn('id', $saleLines->pluck('accessory_id')->filter())->pluck('name_ru', 'id');

        return view('print.return', $this->common() + [
            'size' => $r->query('w') === '58' ? 'tape58' : 'tape80', 'title' => $doc->number, 'doc' => $doc, 'ret' => $ret, 'saleDoc' => $saleDoc,
            'lines' => $lines->map(fn ($l) => (object) ['line' => $l, 'unit' => $units[$saleLines[$l->sale_line_id]->stock_unit_id ?? 0] ?? null, 'acc' => $acc[$saleLines[$l->sale_line_id]->accessory_id ?? 0] ?? null]),
            'payments' => CustomerReturnPayment::query()->where('document_id', $doc->id)->get(), 'store' => Store::query()->find($doc->store_id),
            'duplicate' => $this->duplicate('Ф2', $doc),
        ]);
    }

    // ---- Ф3 Гарантийный талон (A5 / A4 / лента 80) ----
    public function warranty(Request $r, int $document)
    {
        abort_unless(Access::allows('sale.create') || Access::allows('sale.reprint'), 403);
        $doc = $this->posted($document, ['sale']);
        $sale = Sale::query()->where('document_id', $doc->id)->firstOrFail();
        $lines = $this->saleLines($doc)->filter(fn ($x) => $x->unit || ($x->acc && $this->warrantyMonths(null, $x->acc) > 0))->values();
        abort_if($lines->isEmpty(), 422, __('В чеке нет товаров с гарантией'));

        return view('print.warranty', $this->common() + [
            'size' => in_array($r->query('f'), ['a4', 'tape80'], true) ? $r->query('f') : 'a5', 'title' => __('Гарантийный талон').' '.$doc->number,
            'doc' => $doc, 'sale' => $sale, 'lines' => $lines, 'store' => Store::query()->find($doc->store_id), 'seller' => User::query()->find($sale->seller_id),
            'customer' => $sale->customer_id ? Customer::query()->find($sale->customer_id) : null, 'link' => Printing::receiptLink($doc->id),
            'warranty' => fn ($u, $a) => $this->warrantyMonths($u, $a), 'duplicate' => $this->duplicate('Ф3', $doc),
        ]);
    }

    // ---- Ф4 Накладная: приход, перемещение, возврат поставщику ----
    public function invoice(Request $r, int $document)
    {
        abort_unless(Access::allows('stock.receive') || Access::allows('transfer.send') || Access::allows('stock.return_supplier') || Access::isOwner(), 403);
        $doc = $this->posted($document, ['purchase', 'transfer_out', 'supplier_return']);
        $canCost = Access::allows('stock.view_cost');
        $rows = collect();
        $from = $to = $basis = null;
        $currency = 'KZT';
        $store = Store::query()->find($doc->store_id);
        if ($doc->type->value === 'purchase') {
            $p = DB::table('purchases')->where('document_id', $doc->id)->first();
            $from = Supplier::query()->find($p?->supplier_id)?->name;
            $to = $store?->name;
            $basis = $p?->invoice_no ? __('Счёт поставщика').' № '.$p->invoice_no : null;
            $currency = $p?->currency ?? 'KZT';
            $lines = DB::table('purchase_lines')->where('document_id', $doc->id)->orderBy('line_no')->get();
            $rows = $lines->map(fn ($l) => ['name' => $l->line_type === 'unit' ? (Variant::query()->find($l->variant_id)?->title() ?? '') : Accessory::query()->find($l->accessory_id)?->name_ru,
                'code' => $l->imei1 ?: Accessory::query()->find($l->accessory_id)?->barcode, 'qty' => (int) ($l->qty ?: 1), 'price' => (int) $l->price, 'sum' => (int) $l->price * (int) ($l->qty ?: 1)]);
        } else {
            $op = DB::table('stock_ops')->where('document_id', $doc->id)->first();
            $from = $store?->name;
            $to = $doc->type->value === 'transfer_out' ? Store::query()->find($op?->to_store_id)?->name : Supplier::query()->find($op?->supplier_id)?->name;
            $basis = $op?->reason;
            $lines = DB::table('stock_op_lines')->where('document_id', $doc->id)->get();
            $rows = $lines->map(function ($l) use ($doc) {
                $u = $l->stock_unit_id ? StockUnit::query()->with('variant')->find($l->stock_unit_id) : null;

                return ['name' => $u ? $u->variant?->title() : Accessory::query()->find($l->accessory_id)?->name_ru, 'code' => $u ? ($u->imei1 ?: $u->public_code) : Accessory::query()->find($l->accessory_id)?->barcode,
                    'qty' => (int) ($l->qty ?: 1), 'price' => $doc->type->value === 'supplier_return' ? (int) $l->cost_kzt : null, 'sum' => $doc->type->value === 'supplier_return' ? (int) $l->cost_kzt * (int) ($l->qty ?: 1) : null];
            });
        }
        $prices = $canCost && $doc->type->value !== 'transfer_out';
        $titles = ['purchase' => ['Тауарды қабылдау жүкқұжаты', 'Приходная накладная'], 'transfer_out' => ['Ішкі ауыстыру жүкқұжаты', 'Накладная на перемещение'],
            'supplier_return' => ['Жеткізушіге қайтару жүкқұжаты', 'Накладная на возврат поставщику']][$doc->type->value];

        return view('print.invoice', $this->common() + ['size' => 'a4', 'title' => $doc->number, 'doc' => $doc, 'rows' => $rows, 'prices' => $prices, 'currency' => $currency,
            'from' => $from, 'to' => $to, 'basis' => $basis, 'heading' => $titles, 'duplicate' => Printing::logAndIsDuplicate('Ф4', Document::class, $doc->id)]);
    }

    /** Units from ?units=1,2 or ?doc=<purchase id> (all phones of a purchase). */
    private function units(Request $r): Collection
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $r->query('units'))));
        if ($r->query('doc')) {
            $ids = array_merge($ids, DB::table('purchase_lines')->where('document_id', (int) $r->query('doc'))->whereNotNull('stock_unit_id')->pluck('stock_unit_id')->all());
        }
        abort_unless($ids, 422, __('Не выбраны телефоны'));

        return StockUnit::query()->with(['variant.productModel', 'variant.memory', 'variant.color'])->whereIn('id', $ids)->get();
    }

    private function retail(StockUnit $u): ?int
    {
        return $u->sale_price ?? DB::table('variant_prices')->where('variant_id', $u->variant_id)->value('price_kzt');
    }

    // ---- Ф6 Ценник телефона 70×50, лист A4 по 8 ----
    public function priceTags(Request $r)
    {
        abort_unless(Access::allows('label.print'), 403);
        $units = $this->units($r);
        $settings = SiteSettings::current();
        $noKk = $units->filter(fn ($u) => ! ($u->variant?->color?->name_kk) && $u->variant?->color);
        $listed = Listing::query()->whereIn('unit_ref', $units->pluck('id'))->where('public_status', '!=', 'sold')->pluck('public_code')->flip();
        foreach ($units as $u) {
            Printing::logAndIsDuplicate('Ф6', StockUnit::class, $u->id);
        }

        return view('print.price-tags', $this->common() + ['size' => 'sheet', 'title' => __('Ценники'), 'units' => $units->diff($noKk), 'noKk' => $noKk,
            'retail' => fn ($u) => $this->retail($u), 'installment' => fn (int $tenge) => $settings->installmentFrom($tenge), 'listed' => $listed, 'shop' => $this->shopUrl(),
            'grade' => fn ($g, $lang) => $settings->grade($g, $lang)]);
    }

    // ---- Ф7 Термоэтикетка телефона 58×40 ----
    public function labels(Request $r)
    {
        abort_unless(Access::allows('label.print'), 403);
        $units = $this->units($r);
        $listed = Listing::query()->whereIn('unit_ref', $units->pluck('id'))->where('public_status', '!=', 'sold')->pluck('public_code')->flip();
        foreach ($units as $u) {
            Printing::logAndIsDuplicate('Ф7', StockUnit::class, $u->id);
        }

        return view('print.labels', $this->common() + ['size' => 'label58x40', 'title' => __('Этикетки'), 'units' => $units, 'listed' => $listed, 'shop' => $this->shopUrl(),
            'retail' => fn ($u) => $this->retail($u), 'withPrice' => (bool) $this->org()->settings()->get('printing.label_price', true)]);
    }

    // ---- Ф8 Этикетка аксессуара 40×30 / 30×20 ----
    public function accessoryLabels(Request $r)
    {
        abort_unless(Access::allows('label.print'), 403);
        $items = collect();
        if ($r->query('doc')) {
            $items = DB::table('purchase_lines')->where('document_id', (int) $r->query('doc'))->whereNotNull('accessory_id')->get()->map(fn ($l) => [(int) $l->accessory_id, (int) $l->qty]);
        } elseif ($r->query('acc')) {
            $items = collect([[(int) $r->query('acc'), max(1, min(500, (int) $r->query('qty', 1)))]]);
        }
        abort_if($items->isEmpty(), 422, __('Не выбраны аксессуары'));
        $acc = Accessory::query()->whereIn('id', $items->pluck(0))->get()->keyBy('id');
        $prices = DB::table('accessory_prices')->whereIn('accessory_id', $items->pluck(0))->pluck('price_kzt', 'accessory_id');
        $labels = $items->flatMap(fn ($x) => array_fill(0, $x[1], $acc[$x[0]] ?? null))->filter()->values();

        return view('print.acc-labels', $this->common() + ['size' => $r->query('s') === '30x20' ? 'label30x20' : 'label40x30', 'title' => __('Этикетки аксессуаров'),
            'labels' => $labels, 'prices' => $prices, 'ean' => fn (Accessory $a) => self::ean($a)]);
    }

    /** EAN-13 of an accessory: the factory one, else internal «2 + 2 digits of the firm + 9 digits of the id + check» (Ф8). */
    public static function ean(Accessory $a): string
    {
        if (preg_match('/^\d{13}$/', (string) $a->barcode) && self::checkDigit(substr($a->barcode, 0, 12)) === (int) substr($a->barcode, 12)) {
            return $a->barcode;
        }
        $base = '2'.str_pad((string) (CurrentOrganization::instance()->requireId() % 100), 2, '0', STR_PAD_LEFT).str_pad((string) $a->id, 9, '0', STR_PAD_LEFT);

        return $base.self::checkDigit($base);
    }

    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    // ---- Ф9 Квитанция о приёме на гарантию ----
    public function warrantyCase(Request $r, int $id)
    {
        abort_unless(Access::allows('stock.passport') || Access::allows('sale.create'), 403);
        $case = WarrantyCase::query()->findOrFail($id);
        abort_unless($this->storeAllowed($case->store_id ? (int) $case->store_id : null), 403);
        $unit = $case->stock_unit_id ? StockUnit::query()->with('variant')->find($case->stock_unit_id) : null;
        $saleDoc = $case->sale_document_id ? Document::query()->find($case->sale_document_id) : null;
        Printing::logAndIsDuplicate('Ф9', WarrantyCase::class, $case->id);

        return view('print.warranty-case', $this->common() + ['size' => $r->query('f') === 'a5' ? 'a5' : 'tape80', 'title' => (string) $case->number, 'case' => $case, 'unit' => $unit,
            'saleDoc' => $saleDoc, 'customer' => $case->customer_id ? Customer::query()->find($case->customer_id) : null, 'store' => Store::query()->find($case->store_id),
            'until' => $saleDoc && $unit ? Printing::warrantyUntil($saleDoc->occurred_at, $this->warrantyMonths($unit, null)) : null]);
    }

    // ---- Ф10 Итог смены ----
    public function shift(Request $r, int $id)
    {
        abort_unless(Access::allows('shift.open_close') || Access::allows('money.balance'), 403);
        $shift = DB::table('shifts')->where('organization_id', $this->org()->id)->where('id', $id)->first();
        abort_unless($shift && $this->storeAllowed((int) $shift->store_id), 404);
        $p = Period::between(CarbonImmutable::parse($shift->opened_at), ($shift->closed_at ? CarbonImmutable::parse($shift->closed_at) : CarbonImmutable::now()->addMinute()));
        $scope = new Scope([(int) $shift->store_id]);
        $m = app(Metrics::class);
        $t = $m->fold($m->facts($p, $scope));
        $counts = DB::table('shift_counts as c')->join('money_accounts as a', 'a.id', '=', 'c.account_id')->where('c.shift_id', $shift->id)->get(['a.name', 'c.phase', 'c.expected', 'c.counted', 'c.diff']);
        $expenses = (int) DB::table('documents as d')->join('expenses as e', 'e.document_id', '=', 'd.id')->where('d.store_id', $shift->store_id)->where('d.status', 'posted')
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->sum('e.total_kzt');
        Printing::logAndIsDuplicate('Ф10', Shift::class, (int) $shift->id);

        return view('print.shift', $this->common() + ['size' => $r->query('w') === '58' ? 'tape58' : 'tape80', 'title' => $shift->number, 'shift' => $shift, 't' => $t,
            'payments' => $m->payments($p, $scope), 'counts' => $counts, 'expenses' => $expenses, 'store' => Store::query()->find($shift->store_id),
            'names' => User::query()->whereIn('id', [$shift->opened_by, $shift->closed_by])->pluck('name', 'id')]);
    }

    // ---- Ф11 Квитанция о долге (A5) ----
    public function debtReceipt(Request $r, int $document)
    {
        abort_unless(Access::allows('sale.create') || Access::allows('sale.reprint'), 403);
        $doc = $this->posted($document, ['sale']);
        $sale = Sale::query()->where('document_id', $doc->id)->firstOrFail();
        $debt = SalePayment::query()->where('document_id', $doc->id)->where('method', 'debt')->first();
        abort_unless($debt, 422, __('В чеке нет долга'));

        return view('print.debt', $this->common() + ['size' => 'a5', 'title' => __('Квитанция о долге').' '.$doc->number, 'doc' => $doc, 'sale' => $sale, 'debt' => $debt,
            'lines' => $this->saleLines($doc), 'customer' => Customer::query()->find($sale->customer_id), 'store' => Store::query()->find($doc->store_id),
            'seller' => User::query()->find($sale->seller_id), 'duplicate' => $this->duplicate('Ф11', $doc)]);
    }

    // ---- Ф12 Акт сверки долга ----
    public function reconciliation(Request $r)
    {
        $type = $r->query('type') === 'supplier' ? 'supplier' : 'customer';
        abort_unless(Access::allows($type === 'supplier' ? 'debt.supplier_view' : 'debt.customer_report'), 403);
        $id = (int) $r->query('id');
        $party = $type === 'supplier' ? Supplier::query()->findOrFail($id) : Customer::query()->findOrFail($id);
        $tz = $this->org()->timezone;
        $from = CarbonImmutable::parse($r->query('from') ?: now($tz)->startOfYear()->toDateString(), $tz)->startOfDay();
        $to = CarbonImmutable::parse($r->query('to') ?: now($tz)->toDateString(), $tz)->endOfDay();
        $base = DB::table('debt_entries as e')->where('e.organization_id', $this->org()->id)->where('e.counterparty_type', $type)->where('e.counterparty_id', $id);
        $currencies = (clone $base)->distinct()->pluck('e.currency')->whenEmpty(fn ($c) => $c->push('KZT'));
        $blocks = $currencies->map(function ($cur) use ($base, $from, $to) {
            $q = (clone $base)->where('e.currency', $cur);
            $start = (int) (clone $q)->where('e.occurred_at', '<', $from->utc())->sum('e.amount');
            $rows = (clone $q)->leftJoin('documents as d', 'd.id', '=', 'e.document_id')->where('e.occurred_at', '>=', $from->utc())->where('e.occurred_at', '<=', $to->utc())
                ->orderBy('e.occurred_at')->orderBy('e.id')->get(['e.occurred_at', 'd.number', 'd.type', 'e.amount', 'e.amount_kzt', 'e.comment']);

            return ['currency' => $cur, 'start' => $start, 'rows' => $rows, 'end' => $start + (int) $rows->sum('amount')];
        });
        activity('print')->causedBy(auth()->user())->withProperties(['organization_id' => $this->org()->id, 'form' => 'Ф12', 'party' => $type.':'.$id])->log('Ф12');

        return view('print.reconciliation', $this->common() + ['size' => 'a4', 'title' => __('Акт сверки'), 'party' => $party, 'type' => $type, 'from' => $from, 'to' => $to, 'blocks' => $blocks,
            'types' => collect(DocumentType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])]);
    }

    // ---- «Тестовая печать» (§6.3): рамка точного размера, линейка, штрихкод и QR ----
    public function test(Request $r)
    {
        $size = in_array($r->query('s'), ['tape58', 'tape80', 'a4', 'a5', 'label58x40', 'label40x30', 'label30x20'], true) ? $r->query('s') : 'label58x40';

        return view('print.test', $this->common() + ['size' => $size, 'title' => __('Тестовая печать'), 'noAutoPrint' => true]);
    }

    private function shopUrl(): string
    {
        $port = request()->getPort();

        return (request()->isSecure() ? 'https://' : 'http://').config('prime.shop_domain').($port && ! in_array($port, [80, 443], true) ? ':'.$port : '');
    }
}
