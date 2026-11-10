<?php

namespace App\Http\Controllers\TradeIn;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Ledger\Models\Document;
use App\Domain\TradeIn\Models\TradeInAct;
use App\Domain\TradeIn\Models\TradeInPayout;
use App\Support\Iin\Iin;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\View\View;

/**
 * Data of the act Ф5 (TZ 06) as a temporary printable page: the person with IIN (full for those with
 * customer.view_iin, a mask for others), the device, the checklist, the sum and how it was paid. The printing module
 * replaces the view «tradein.print» and keeps the route erp.tradein.print.
 */
class ActPrintController
{
    public function __invoke(int $document): View
    {
        abort_unless(Access::allows('tradein.create'), 403);
        $doc = Document::query()->where('type', 'tradein')->findOrFail($document);
        $act = TradeInAct::query()->where('document_id', $doc->id)->firstOrFail();
        $customer = $act->customer_id ? Customer::query()->find($act->customer_id) : null;
        $fullIin = Access::allows('customer.view_iin');

        return view('tradein.print', [
            'doc' => $doc, 'act' => $act, 'customer' => $customer,
            'iin' => $customer?->iin ? ($fullIin ? $customer->iin : Iin::mask(substr($customer->iin, -4))) : '—',
            'docNo' => $fullIin ? $act->id_document_no : ($act->id_document_no ? '•••'.substr($act->id_document_no, -3) : ''),
            'variant' => Variant::query()->find($act->variant_id),
            'payouts' => TradeInPayout::query()->where('document_id', $doc->id)->get(),
            'org' => CurrentOrganization::instance()->require(),
        ]);
    }
}
