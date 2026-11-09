<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class AccessoryCategory extends Model
{
    protected $table = 'accessory_categories';

    protected $fillable = ['name_ru', 'name_kk', 'sort'];
}
