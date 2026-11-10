<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Shift of a store (TZ 02 §8, audit №57): one open shift per store, covering all its cash registers. Not an
 * accounting document: number СМН-…, no journal entries; cash differences are documents РКС (Money module).
 */
class Shift extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id', 'status', 'number'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
