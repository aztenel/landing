<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Queries\UnitList;
use App\Support\Ui\Access;
use App\Support\Ui\WorkingStore;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel of «Склад — Электроника» with the same filters as the screen; cost and supplier columns only by right. */
class ExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless(Access::allows('stock.passport'), 403);
        $user = $request->user();
        $filters = $request->only(['tab', 'q', 'model_id', 'condition', 'store_id', 'supplier_id', 'imei', 'age', 'no_cost']);
        $canCost = Access::allows('stock.view_cost');
        $canSupplier = Access::allows('stock.view_supplier');
        $stores = WorkingStore::available($user)->pluck('name', 'id');
        $suppliers = $canSupplier ? Supplier::query()->pluck('name', 'id') : collect();
        $prices = VariantPrice::query()->pluck('price_kzt', 'variant_id');
        activity('stock')->causedBy($user)->withProperties(['filters' => $filters])->log('stock_exported');

        return response()->streamDownload(function () use ($filters, $user, $canCost, $canSupplier, $stores, $suppliers, $prices) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $head = ['Модель', 'Память', 'Цвет', 'Состояние', 'IMEI 1', 'IMEI 2', 'Серийный', 'Код', 'Статус', 'Магазин', 'Цена, ₸', 'Дней на складе'];
            if ($canCost) {
                $head[] = 'Себестоимость, ₸';
            }
            if ($canSupplier) {
                $head[] = 'Поставщик';
            }
            $writer->addRow(Row::fromValues($head));
            UnitList::query($filters, $user)->chunk(500, function ($units) use ($writer, $canCost, $canSupplier, $stores, $suppliers, $prices) {
                foreach ($units as $u) {
                    $price = $u->sale_price ?? ($prices[$u->variant_id] ?? null);
                    $row = [
                        $u->variant?->productModel?->name_ru, $u->variant?->memory?->name, $u->variant?->color?->name_ru,
                        $u->condition?->value === 'used' ? 'б/у'.($u->grade ? ' '.$u->grade : '') : 'новый',
                        (string) $u->imei1, (string) $u->imei2, (string) $u->serial, $u->public_code, UnitStatus::labelOf($u->status),
                        $u->location_type === 'store' ? ($stores[$u->location_id] ?? '') : 'в пути',
                        $price === null ? null : intdiv((int) $price, 100), $u->received_at ? (int) $u->received_at->diffInDays(now()) : null,
                    ];
                    if ($canCost) {
                        $row[] = $u->cost_unknown ? null : intdiv((int) $u->cost_kzt, 100);
                    }
                    if ($canSupplier) {
                        $row[] = $suppliers[$u->supplier_id] ?? '';
                    }
                    $writer->addRow(Row::fromValues($row));
                }
            });
            $writer->close();
        }, 'sklad-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
