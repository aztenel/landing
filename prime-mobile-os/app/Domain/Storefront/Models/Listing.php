<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * storefront_listings — the public card of one phone (TZ 05 §2.1). Only the allowed fields: no IMEI, cost, supplier,
 * customers. Written ONLY by ListingBuilder; the site only reads it.
 */
class Listing extends Model
{
    use BelongsToOrganization;

    protected $table = 'storefront_listings';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['public_checks' => 'array', 'badges' => 'array', 'photos' => 'array', 'imei_verified' => 'boolean',
            'published_at' => 'datetime', 'sold_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function title(string $lang = 'ru'): string
    {
        return $lang === 'kk' ? $this->title_kk : $this->title_ru;
    }

    public function photo(string $size = 'md', int $i = 0): ?string
    {
        $p = $this->photos[$i][$size] ?? null;

        return $p ? '/storage/'.$p : null;
    }

    public function discountPercent(): ?int
    {
        return $this->compare_at_price > $this->price ? (int) floor(($this->compare_at_price - $this->price) * 100 / $this->compare_at_price) : null;
    }
}
