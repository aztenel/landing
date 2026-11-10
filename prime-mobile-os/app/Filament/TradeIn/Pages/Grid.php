<?php

namespace App\Filament\TradeIn\Pages;

use App\Domain\Catalog\Models\Memory;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\TradeIn\Models\GridDeduction;
use App\Domain\TradeIn\Models\GridPrice;
use App\Domain\TradeIn\Services\TradeInGrid;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Money\Money;
use Filament\Pages\Page;

/** Э31 «Сетка выкупа» (/tradein/grid): base prices per model + memory and the deductions; only the owner edits. */
class Grid extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'tradein.grid';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tradein/grid';

    protected static string $view = 'tradein.pages.grid';

    public array $row = ['model_id' => null, 'memory_id' => null, 'price' => ''];

    /** @var array<string, array{kind: string, value: string}> */
    public array $deductions = [];

    public function getTitle(): string
    {
        return __('Сетка выкупа');
    }

    public function mount(): void
    {
        $saved = GridDeduction::query()->get()->keyBy('code');
        foreach (TradeInGrid::DEDUCTIONS as $code => $label) {
            $d = $saved[$code] ?? null;
            $this->deductions[$code] = ['kind' => $d?->kind ?? 'amount', 'value' => $d ? (string) ($d->kind === 'amount' ? intdiv((int) $d->value, 100) : $d->value / 100) : ''];
        }
    }

    public function savePrice(): void
    {
        $data = $this->validate(['row.model_id' => 'required|exists:models,id', 'row.memory_id' => 'nullable|exists:memories,id', 'row.price' => 'required'])['row'];
        GridPrice::query()->updateOrCreate(['model_id' => $data['model_id'], 'memory_id' => $data['memory_id'] ?: null],
            ['base_price_kzt' => Money::kzt((int) preg_replace('/\D/', '', (string) $data['price']))->amount, 'updated_by' => auth()->id()]);
        $this->row['price'] = '';
    }

    public function removePrice(int $id): void
    {
        GridPrice::query()->whereKey($id)->delete();
    }

    public function saveDeductions(): void
    {
        foreach ($this->deductions as $code => $d) {
            if (! array_key_exists($code, TradeInGrid::DEDUCTIONS)) {
                continue;
            }
            $value = str_replace([' ', ','], ['', '.'], (string) $d['value']);
            if ($value === '') {
                GridDeduction::query()->where('code', $code)->delete();

                continue;
            }
            $kind = $d['kind'] === 'percent' ? 'percent' : 'amount';
            GridDeduction::query()->updateOrCreate(['code' => $code], ['kind' => $kind, 'value' => $kind === 'percent' ? (int) round((float) $value * 100) : (int) round((float) $value) * 100]);
        }
    }

    protected function getViewData(): array
    {
        return [
            'prices' => GridPrice::query()->orderBy('model_id')->get(),
            'models' => ProductModel::query()->orderBy('name_ru')->pluck('name_ru', 'id'),
            'memories' => Memory::query()->orderBy('gb')->pluck('name', 'id'),
        ];
    }
}
