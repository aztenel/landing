<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class Color extends Model
{
    protected $table = 'colors';

    protected $fillable = ['name_ru', 'name_kk', 'name_en', 'hex'];
}
