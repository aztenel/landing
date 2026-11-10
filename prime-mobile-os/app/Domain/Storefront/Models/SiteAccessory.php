<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SiteAccessory extends Model
{
    use BelongsToOrganization;

    protected $table = 'storefront_accessories';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['photos' => 'array', 'updated_at' => 'datetime'];
    }

    public function photo(string $size = 'md'): ?string
    {
        $p = $this->photos[0][$size] ?? null;

        return $p ? '/storage/'.$p : null;
    }
}
