<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class ProductType extends Model
{
    protected $table = 'product_types';

    protected $fillable = ['code', 'name_ru', 'name_kk', 'is_serialized', 'sort'];
}
