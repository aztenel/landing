<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class Ram extends Model
{
    protected $table = 'rams';

    protected $fillable = ['name', 'gb'];
}
