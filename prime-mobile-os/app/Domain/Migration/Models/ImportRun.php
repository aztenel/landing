<?php

namespace App\Domain\Migration\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ImportRun extends Model
{
    use BelongsToOrganization;

    protected $table = 'import_runs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'fields' => 'array', 'totals' => 'array', 'columns' => 'array'];
    }
}
