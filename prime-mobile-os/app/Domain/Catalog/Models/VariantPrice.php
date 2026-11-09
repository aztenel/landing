<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;

/** Retail price of the organization in tiyn (catalog is global, prices are per organization). */
class VariantPrice extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = ['organization_id', 'variant_id', 'price_kzt', 'created_by'];

    protected function casts(): array
    {
        return ['price_kzt' => 'integer'];
    }
}
