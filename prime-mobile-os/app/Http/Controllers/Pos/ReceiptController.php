<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Models\Store;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Temporary receipt 80 mm (window.print) of a posted sale. The printing module replaces the view «pos.receipt»
 * with Ф1 (kk/ru, QR, warranty) keeping the route name erp.pos.receipt (docs/modules/pos.md).
 */
class ReceiptController
{
    public function __invoke(Request $request, int $document, AccessChecker $access): View
    {
        $doc = Document::query()->whereKey($document)->where('type', DocumentType::Sale->value)->firstOrFail(); // other firm: 404
        $user = $request->user();
        abort_unless($access->can($user, 'sale.create') && ($access->isOwner($user) || $access->membership($user)?->allowsStore((int) $doc->store_id)), 403);

        return view('pos.receipt', [
            'doc' => $doc,
            'sale' => Sale::query()->where('document_id', $doc->id)->firstOrFail(),
            'lines' => SaleLine::query()->where('document_id', $doc->id)->orderBy('line_no')->get(),
            'payments' => SalePayment::query()->where('document_id', $doc->id)->orderBy('id')->get(),
            'store' => Store::query()->find($doc->store_id),
            'org' => CurrentOrganization::instance()->require(),
            'tz' => CurrentOrganization::instance()->require()->timezone,
        ]);
    }
}
