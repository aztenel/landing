<?php

namespace App\Domain\Warehouse\Drafts;

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Warehouse\Models\StockOp;
use App\Domain\Warehouse\Models\StockOpLine;
use App\Support\Money\Money;

/**
 * Warehouse documents (TZ 02 №24–32, №48–49):
 *
 *   StockOpDraft::transferOut($storeA, $storeB)->unit($u)->accessory($case, 5)->post($seller);        // ПРМ отправка
 *   StockOpDraft::transferIn($outDoc)->unit($u)->refuseUnit($u2)->accessory($case, 4, refused: 1)->post($sellerB);
 *   StockOpDraft::writeOff($store, 'брак')->unit($u, 'разбит экран')->accessory($glass, 2)->post($admin); // СПС
 *   StockOpDraft::supplierReturn($store, $supplier, 'debt', Money::kzt(400000))->unit($u)->post($admin); // ВЗП
 *   StockOpDraft::revision($store, $revisionId)->shortage($u)->found($u2)->accessory($case, -2)->post($admin); // РЕВ
 *   StockOpDraft::unitEdit($u, ['imei1' => '356…'], 'опечатка')->post($admin);                          // ИЗХ
 *   StockOpDraft::costAdjustment($u, Money::kzt(-5000), 'скидка поставщика')->post($owner);           // КСБ
 */
final class StockOpDraft extends DraftBuilder
{
    private DocumentType $kind;

    private array $head = [];

    private array $lines = [];

    private static function make(DocumentType $kind, Store|int|null $store, array $head = []): self
    {
        $d = new self;
        $d->kind = $kind;
        $d->storeId = self::storeId($store);
        $d->head = $head;

        return $d;
    }

    public static function transferOut(Store|int $from, Store|int $to): self
    {
        return self::make(DocumentType::TransferOut, $from, ['to_store_id' => $to instanceof Store ? $to->id : $to, 'status' => 'in_transit']);
    }

    public static function transferIn(Document $out): self
    {
        $to = StockOp::query()->where('document_id', $out->id)->value('to_store_id');

        return self::make(DocumentType::TransferIn, (int) $to, ['transfer_document_id' => $out->id]);
    }

    public static function writeOff(Store|int $store, string $reason): self
    {
        return self::make(DocumentType::WriteOff, $store, ['reason' => $reason]);
    }

    public static function supplierReturn(Store|int $store, int $supplierId, string $mode, Money $amount, ?int $accountId = null): self
    {
        return self::make(DocumentType::SupplierReturn, $store, ['supplier_id' => $supplierId, 'refund_mode' => $mode, 'refund_amount' => self::kzt($amount), 'refund_account_id' => $accountId]);
    }

    public static function revision(Store|int $store, ?int $revisionId = null): self
    {
        return self::make(DocumentType::Revision, $store, ['revision_id' => $revisionId]);
    }

    public static function unitEdit(StockUnit $unit, array $changes, string $reason): self
    {
        $d = self::make(DocumentType::UnitEdit, $unit->location_type === 'store' ? (int) $unit->location_id : null, ['changes' => $changes, 'reason' => $reason]);

        return $d->unit($unit);
    }

    public static function costAdjustment(StockUnit $unit, Money $delta, string $reason): self
    {
        $d = self::make(DocumentType::CostAdjustment, $unit->location_type === 'store' ? (int) $unit->location_id : null, ['reason' => $reason]);
        $d->lines[] = ['line_type' => 'unit', 'stock_unit_id' => $unit->id, 'qty' => 0, 'cost_kzt' => $delta->amount];

        return $d;
    }

    public function unit(StockUnit|int $unit, ?string $reason = null): self
    {
        $this->lines[] = ['line_type' => 'unit', 'stock_unit_id' => $unit instanceof StockUnit ? $unit->id : $unit, 'qty' => 1, 'reason' => $reason];

        return $this;
    }

    public function refuseUnit(StockUnit|int $unit, ?string $reason = null): self
    {
        $this->lines[] = ['line_type' => 'unit', 'stock_unit_id' => $unit instanceof StockUnit ? $unit->id : $unit, 'qty' => 0, 'refused_qty' => 1, 'reason' => $reason];

        return $this;
    }

    /** Revision: the phone is not found (→ written off as shortage). */
    public function shortage(StockUnit|int $unit): self
    {
        $this->lines[] = ['line_type' => 'unit', 'stock_unit_id' => $unit instanceof StockUnit ? $unit->id : $unit, 'qty' => -1, 'kind' => 'shortage'];

        return $this;
    }

    /** Revision: a phone written off before is found again (its cost comes back). */
    public function found(StockUnit|int $unit): self
    {
        $this->lines[] = ['line_type' => 'unit', 'stock_unit_id' => $unit instanceof StockUnit ? $unit->id : $unit, 'qty' => 1, 'kind' => 'surplus'];

        return $this;
    }

    /** Accessories: qty out (write-off, return, transfer) or ± difference (revision); transfer in: accepted / refused. */
    public function accessory(int $accessoryId, int $qty, int $refused = 0, ?string $reason = null): self
    {
        $this->lines[] = ['line_type' => 'accessory', 'accessory_id' => $accessoryId, 'qty' => $qty, 'refused_qty' => $refused, 'reason' => $reason,
            'kind' => $this->kind === DocumentType::Revision ? ($qty < 0 ? 'shortage' : 'surplus') : null];

        return $this;
    }

    protected function type(): DocumentType
    {
        return $this->kind;
    }

    protected function writeDetails(Document $document): void
    {
        StockOp::query()->create(['document_id' => $document->id] + $this->head);
        foreach ($this->lines as $line) {
            StockOpLine::query()->create(['document_id' => $document->id] + $line);
        }
    }
}
