<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalog directory (assumption Д1: shared by all organizations). */
class Memory extends Model
{
    protected $table = 'memories';

    protected $fillable = ['name', 'gb', 'sort'];
}
