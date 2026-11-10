<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use BelongsToOrganization;

    protected $table = 'storefront_banners';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
