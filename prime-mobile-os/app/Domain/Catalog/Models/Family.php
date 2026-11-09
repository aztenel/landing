<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class Family extends Model
{
    protected $table = 'families';

    protected $fillable = ['product_type_id', 'brand', 'name', 'sort'];
}
