<?php

namespace App\Http\Controllers\Pos;

/** erp.pos.receipt (contract of docs/modules/pos.md) now opens the print form Ф1 of the printing module. */
class ReceiptController
{
    public function __invoke(int $document)
    {
        return redirect()->route('erp.print.sale', ['document' => $document] + request()->query());
    }
}
