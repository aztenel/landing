<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accessory sold by quantity: article (sku), barcode, names ru/kk, category. Global (Д1). */
class Accessory extends Model
{
    protected $fillable = ['category_id', 'sku', 'barcode', 'name_ru', 'name_kk', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AccessoryCategory::class, 'category_id');
    }
}
