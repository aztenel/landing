<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** «Проверен в госбазе IMEI: кто, когда, результат» (architecture §2.9, TZ 02 §10.4 p.3). Append-only. */
class ImeiCheck extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'stock_unit_id', 'imei', 'result', 'source', 'checked_by', 'checked_at', 'attachment_path', 'comment'];

    protected function casts(): array
    {
        return ['result' => ImeiCheckResult::class, 'checked_at' => 'datetime'];
    }
}
