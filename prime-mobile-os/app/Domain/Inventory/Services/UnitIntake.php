<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Enums\IdentifierKind;
use App\Domain\Inventory\Enums\UnitCondition;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\PostingContext;
use App\Support\Imei\Imei;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Taking a phone into stock from a line (purchase_lines, opening_balance_lines; later trade-in acts, surplus):
 * IMEI validation (15 digits + Luhn, normalization), duplicates in the document and in the organization
 * (layer 1 of IMEI protection, the DB unique index is the last resort), creation of stock_units + unit_identifiers.
 * The status/location/cost are then set by InventoryLedger::unitIn().
 */
class UnitIntake
{
    /**
     * @param  iterable<Model>  $lines  rows with variant_id, imei1, imei2, serial, condition, grade, battery_percent…
     */
    public function validate(iterable $lines): void
    {
        $seen = [];
        $values = [];
        foreach ($lines as $line) {
            if (! $line->variant_id || ! Variant::query()->whereKey($line->variant_id)->exists()) {
                throw PostingException::because('variant_required', 'Укажите модель, память и цвет телефона');
            }
            foreach ($this->identifiers($line) as $kind => $value) {
                if (isset($seen[$value])) {
                    throw PostingException::because('duplicate_imei', "IMEI {$value} дважды в документе");
                }
                $seen[$value] = true;
                $values[] = $value;
            }
            $condition = UnitCondition::tryFrom((string) ($line->condition ?? 'new'));
            if ($condition === null) {
                throw PostingException::because('bad_condition', 'Состояние: новый или б/у');
            }
            if ($line->grade !== null && ! in_array($line->grade, ['A', 'B', 'C'], true)) {
                throw PostingException::because('bad_grade', 'Оценка б/у: A, B или C');
            }
            if ($line->battery_percent !== null && ($line->battery_percent < 0 || $line->battery_percent > 100)) {
                throw PostingException::because('bad_battery', 'Батарея: от 0 до 100 %');
            }
        }

        if ($values) {
            $existing = UnitIdentifier::query()->whereIn('value', $values)->first();
            if ($existing) {
                $unit = StockUnit::query()->find($existing->stock_unit_id);
                throw PostingException::because('imei_exists', sprintf(
                    'IMEI %s уже есть в базе: %s (%s). Один телефон — один экземпляр',
                    $existing->value, $unit?->public_code, UnitStatus::labelOf($unit?->status),
                ), ['stock_unit_id' => $unit?->id]);
            }
        }
    }

    /** Create the unit (no status yet) and its identifiers; registers it in the posting lock set. */
    public function create(PostingContext $ctx, Model $line, ?int $supplierId): StockUnit
    {
        $ids = $this->identifiers($line);
        $unit = new StockUnit;
        $unit->forceFill([
            'variant_id' => $line->variant_id,
            'condition' => $line->condition ?? UnitCondition::New->value,
            'grade' => $line->grade,
            'battery_percent' => $line->battery_percent,
            'year' => $line->year,
            'defects' => $line->defects,
            'imei1' => $ids[IdentifierKind::Imei1->value] ?? null,
            'imei2' => $ids[IdentifierKind::Imei2->value] ?? null,
            'serial' => $ids[IdentifierKind::Serial->value] ?? null,
            'show_on_site' => (bool) $line->show_on_site,
            'sale_price' => $line->sale_price,
            'supplier_id' => $supplierId,
            'purchase_document_id' => $ctx->document->id,
            'received_at' => $ctx->document->occurred_at,
            'created_by' => $ctx->actor->id,
        ])->save();

        foreach ($ids as $kind => $value) {
            UnitIdentifier::query()->create(['stock_unit_id' => $unit->id, 'kind' => $kind, 'value' => $value]);
        }
        $ctx->locks->putUnit($unit->refresh());

        return $unit;
    }

    /** @return array<string, string> kind => normalized value */
    private function identifiers(Model $line): array
    {
        $ids = [];
        try {
            if ($line->imei1) {
                $ids[IdentifierKind::Imei1->value] = Imei::assertValid($line->imei1);
            }
            if ($line->imei2) {
                $ids[IdentifierKind::Imei2->value] = Imei::assertValid($line->imei2);
            }
        } catch (InvalidArgumentException $e) {
            throw PostingException::because('bad_imei', $e->getMessage());
        }
        if ($line->serial) {
            if (! Imei::isSerial($line->serial)) {
                throw PostingException::because('bad_serial', "Серийный номер {$line->serial}: 6–20 латинских букв и цифр");
            }
            $ids[IdentifierKind::Serial->value] = Imei::normalize($line->serial);
        }
        if ($ids === []) {
            throw PostingException::because('imei_required', 'Укажите IMEI или серийный номер');
        }

        return $ids;
    }
}
