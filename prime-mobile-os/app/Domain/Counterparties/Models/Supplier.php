<?php

namespace App\Domain\Counterparties\Models;

use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;

/** Supplier (a repair master is a supplier with is_repair_master). Supplier names are hidden without stock.view_supplier. */
class Supplier extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = ['organization_id', 'name', 'phone', 'bin_iin', 'contact_person', 'is_repair_master', 'is_active', 'comment', 'created_by'];

    protected function casts(): array
    {
        return ['is_repair_master' => 'boolean', 'is_active' => 'boolean'];
    }
}
