<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accessory sold by quantity: article (sku), barcode, names ru/kk, category. Belongs to a firm (audit №9); phone models stay global (Д1). */
class Accessory extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['category_id', 'sku', 'barcode', 'name_ru', 'name_kk', 'warranty_months', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AccessoryCategory::class, 'category_id');
    }
}
