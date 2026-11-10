<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SiteStore extends Model
{
    use BelongsToOrganization;

    protected $table = 'storefront_stores';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'updated_at' => 'datetime'];
    }
}
