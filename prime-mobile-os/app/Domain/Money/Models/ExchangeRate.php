<?php

namespace App\Domain\Money\Models;

use App\Support\Money\Rate;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Exchange rate history (append-only). Effective rate = latest row with valid_from ≤ moment (TZ 02 §3.3).
 */
class ExchangeRate extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'currency', 'rate', 'valid_from', 'set_by', 'comment'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime'];
    }

    public function toRate(): Rate
    {
        return Rate::of((string) $this->rate);
    }
}
