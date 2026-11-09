<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «What phone it is»: model + memory + color + RAM (architecture §2.2). Global (Д1); prices per organization.
 */
class Variant extends Model
{
    protected $fillable = ['model_id', 'memory_id', 'color_id', 'ram_id', 'sku', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'model_id');
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function ram(): BelongsTo
    {
        return $this->belongsTo(Ram::class);
    }

    /** «iPhone 16 Pro 256 ГБ Desert Titanium» */
    public function title(): string
    {
        $this->loadMissing(['productModel', 'memory', 'color', 'ram']);

        return trim(implode(' ', array_filter([
            $this->productModel?->name_ru,
            $this->memory?->name,
            $this->ram ? $this->ram->name.' ОЗУ' : null,
            $this->color?->name_ru,
        ])));
    }

    public static function findOrCreateFor(int $modelId, ?int $memoryId, ?int $colorId, ?int $ramId = null): self
    {
        return static::query()->firstOrCreate([
            'model_id' => $modelId, 'memory_id' => $memoryId, 'color_id' => $colorId, 'ram_id' => $ramId,
        ]);
    }
}
