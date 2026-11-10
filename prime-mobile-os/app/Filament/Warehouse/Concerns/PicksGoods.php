<?php

namespace App\Filament\Warehouse\Concerns;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Inventory\Models\StockUnit;
use Illuminate\Support\Collection;

/**
 * A list of goods filled by scanning (IMEI / PM-code → a phone, barcode / SKU → +1 accessory). Only ids and quantities
 * live in the component; what the phone is and where it stands is read from the database when shown and posted.
 */
trait PicksGoods
{
    /** @var list<array{kind: string, id: int, qty: int}> */
    public array $goods = [];

    public string $code = '';

    public function addCode(?string $code = null): void
    {
        $code = trim($code ?? $this->code);
        $this->code = '';
        if ($code === '') {
            return;
        }
        $unit = StockUnit::query()->where('public_code', mb_strtoupper($code))->first() ?? StockUnit::findByIdentifier($code);
        if ($unit) {
            if (! collect($this->goods)->contains(fn ($g) => $g['kind'] === 'unit' && $g['id'] === $unit->id)) {
                $this->goods[] = ['kind' => 'unit', 'id' => $unit->id, 'qty' => 1];
            }

            return;
        }
        $a = Accessory::query()->where('is_active', true)->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        if (! $a) {
            $this->error = __('Не нашли «:c»', ['c' => $code]);

            return;
        }
        foreach ($this->goods as $i => $g) {
            if ($g['kind'] === 'accessory' && $g['id'] === $a->id) {
                $this->goods[$i]['qty']++;

                return;
            }
        }
        $this->goods[] = ['kind' => 'accessory', 'id' => $a->id, 'qty' => 1];
    }

    public function dropGood(int $i): void
    {
        unset($this->goods[$i]);
        $this->goods = array_values($this->goods);
    }

    /** @return Collection<int, array{i: int, kind: string, id: int, qty: int, title: string, code: ?string, status: ?string}> */
    public function goodsRows(): Collection
    {
        return collect($this->goods)->map(function ($g, $i) {
            if ($g['kind'] === 'unit') {
                $u = StockUnit::query()->with('variant')->find($g['id']);

                return $g + ['i' => $i, 'title' => $u?->variant?->title() ?? '', 'code' => $u?->public_code, 'status' => \App\Domain\Inventory\Enums\UnitStatus::labelOf($u?->status)];
            }

            return $g + ['i' => $i, 'title' => Accessory::query()->find($g['id'])?->name_ru ?? '', 'code' => null, 'status' => null];
        });
    }

    /** @param \App\Domain\Warehouse\Drafts\StockOpDraft $draft */
    protected function addGoodsTo($draft, ?string $reason = null): void
    {
        foreach ($this->goods as $g) {
            $g['kind'] === 'unit' ? $draft->unit((int) $g['id'], $reason) : $draft->accessory((int) $g['id'], max(1, (int) $g['qty']), 0, $reason);
        }
    }
}
