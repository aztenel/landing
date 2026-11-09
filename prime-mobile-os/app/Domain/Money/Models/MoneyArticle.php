<?php

namespace App\Domain\Money\Models;

use App\Domain\Money\Enums\ArticleCode;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Meaning of a money entry for reports (TZ 02 §11.4).
 */
class MoneyArticle extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'code', 'name_ru', 'name_kk', 'kind', 'affects_profit', 'is_system', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['affects_profit' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function byCode(ArticleCode|string $code): self
    {
        $code = $code instanceof ArticleCode ? $code->value : $code;

        return static::query()->where('code', $code)->firstOrFail();
    }

    public function isExpense(): bool
    {
        return $this->kind === 'expense';
    }
}
