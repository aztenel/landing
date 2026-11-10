<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SitePage extends Model
{
    use BelongsToOrganization;

    protected $table = 'storefront_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_draft' => 'boolean'];
    }
}
