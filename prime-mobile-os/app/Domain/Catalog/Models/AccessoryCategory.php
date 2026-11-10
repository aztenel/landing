<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Accessory category of a firm (audit №9). */
class AccessoryCategory extends Model
{
    use BelongsToOrganization;

    protected $table = 'accessory_categories';

    protected $fillable = ['name_ru', 'name_kk', 'warranty_months', 'sort'];
}
