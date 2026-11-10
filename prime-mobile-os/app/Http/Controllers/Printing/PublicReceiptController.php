<?php

namespace App\Http\Controllers\Printing;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\Document;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Store;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** /r/<token>: the buyer's electronic receipt and warranty (TZ 06 §6.5). Lives 3 years; masked IMEI. */
class PublicReceiptController
{
    public function __invoke(string $token)
    {
        $link = DB::table('receipt_links')->where('organization_id', CurrentOrganization::instance()->requireId())->where('token', $token)->first();
        abort_unless($link && Carbon::parse($link->created_at)->gt(now()->subYears(3)), 404);
        $doc = Document::query()->findOrFail($link->document_id);
        $org = CurrentOrganization::instance()->require();
        $lines = SaleLine::query()->where('document_id', $doc->id)->orderBy('line_no')->get();
        $units = StockUnit::query()->with('variant')->whereIn('id', $lines->pluck('stock_unit_id')->filter())->get()->keyBy('id');
        $acc = Accessory::query()->whereIn('id', $lines->pluck('accessory_id')->filter())->get()->keyBy('id');
        $s = $org->settings();

        return response()->view('print.public', [
            'size' => 'tape80', 'title' => $doc->number, 'noAutoPrint' => true, 'org' => $org, 'tz' => $org->timezone,
            'brand' => $s->get('brand.display_name') ?: $org->name, 'store' => Store::query()->find($doc->store_id), 'doc' => $doc,
            'sale' => Sale::query()->where('document_id', $doc->id)->firstOrFail(), 'payments' => SalePayment::query()->where('document_id', $doc->id)->get(),
            'lines' => $lines->map(fn ($l) => (object) ['line' => $l, 'unit' => $units[$l->stock_unit_id] ?? null, 'acc' => $acc[$l->accessory_id] ?? null]),
            'warranty' => fn ($u, $a) => $u ? (int) $s->get($u->condition?->value === 'used' ? 'warranty.used_months' : 'warranty.new_months') : ($a ? (int) ($a->warranty_months ?? $s->get('warranty.accessory_months')) : 0),
        ])->header('X-Robots-Tag', 'noindex');
    }
}
