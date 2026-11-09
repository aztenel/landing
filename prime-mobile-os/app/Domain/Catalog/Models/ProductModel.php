<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Model of a device: «iPhone 15 Pro» (table `models`). Global (Д1). */
class ProductModel extends Model
{
    protected $table = 'models';

    protected $fillable = ['family_id', 'name_ru', 'name_kk', 'name_en', 'release_year', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class, 'model_id');
    }
}
