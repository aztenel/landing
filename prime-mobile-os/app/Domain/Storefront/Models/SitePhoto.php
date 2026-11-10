<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A photo for the site (TZ 05 §3): of a unit, of a model + colour (new sealed), or of an accessory. */
class SitePhoto extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['files' => 'array', 'flags' => 'array', 'needs_review' => 'boolean', 'reviewed_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function url(string $size = 'md'): ?string
    {
        $path = $this->files[$size] ?? null;

        return $path ? '/storage/'.$path : null;
    }
}
