<?php

namespace App\Domain\TradeIn\Services;

use App\Domain\Catalog\Models\Variant;
use App\Domain\TradeIn\Models\GridDeduction;
use App\Domain\TradeIn\Models\GridPrice;
use App\Support\Money\Money;

/**
 * Buy-back grid (audit №29): base price per model + memory for «grade A, battery ≥ 85 %», minus the firm's deductions
 * by the checklist; not below 0, rounded down to 1 000 ₸. null — no grid row (every buy-back is a request except the
 * owner's).
 */
class TradeInGrid
{
    public const DEDUCTIONS = [
        'grade_b' => 'Грейд B', 'grade_c' => 'Грейд C', 'battery_low' => 'Батарея ниже 80 %', 'screen' => 'Заменён экран',
        'parts' => 'Заменены другие детали', 'no_box' => 'Нет коробки', 'body' => 'Повреждён корпус',
    ];

    /**
     * @param  array{grade: string, battery: ?int, replaced: list<string>, kit: list<string>, body_damage?: bool}  $state
     * @return array{base: int, deductions: list<array{label: string, amount: int}>, price: int}|null tiyn
     */
    public function quote(Variant|int $variant, array $state): ?array
    {
        $variant = $variant instanceof Variant ? $variant : Variant::query()->findOrFail($variant);
        $row = GridPrice::query()->where('model_id', $variant->model_id)->where('memory_id', $variant->memory_id)->first()
            ?? GridPrice::query()->where('model_id', $variant->model_id)->whereNull('memory_id')->first();
        if (! $row) {
            return null;
        }
        $base = (int) $row->base_price_kzt;
        $codes = [];
        if (($state['grade'] ?? 'A') === 'B') {
            $codes[] = 'grade_b';
        }
        if (($state['grade'] ?? 'A') === 'C') {
            $codes[] = 'grade_c';
        }
        if (($state['battery'] ?? 100) !== null && (int) ($state['battery'] ?? 100) < 80) {
            $codes[] = 'battery_low';
        }
        $replaced = $state['replaced'] ?? [];
        if (in_array('screen', $replaced, true)) {
            $codes[] = 'screen';
        }
        if (array_diff($replaced, ['screen']) !== []) {
            $codes[] = 'parts';
        }
        if (! in_array('box', $state['kit'] ?? [], true)) {
            $codes[] = 'no_box';
        }
        if (! empty($state['body_damage'])) {
            $codes[] = 'body';
        }
        $rules = GridDeduction::query()->whereIn('code', $codes)->get()->keyBy('code');
        $lines = [];
        $total = 0;
        foreach ($codes as $code) {
            $rule = $rules[$code] ?? null;
            if (! $rule) {
                continue;
            }
            $amount = $rule->kind === 'percent' ? intdiv($base * (int) $rule->value, 10000) : (int) $rule->value;
            $lines[] = ['label' => self::DEDUCTIONS[$code], 'amount' => $amount];
            $total += $amount;
        }
        $step = 1000 * Money::TENGE;

        return ['base' => $base, 'deductions' => $lines, 'price' => intdiv(max(0, $base - $total), $step) * $step];
    }
}
