<?php

namespace App\Domain\Storefront\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToOrganization;

    protected $table = 'site_leads';

    public const UPDATED_AT = null;

    public const TYPES = ['ask' => 'Вопрос по товару', 'installment' => 'Рассрочка', 'book' => 'Бронь', 'similar' => 'Похожий', 'to_order' => 'Под заказ', 'tradein' => 'Trade-in', 'general' => 'Общий'];

    public const OUTCOMES = ['bought' => 'Купил', 'no_answer' => 'Не ответил', 'changed_mind' => 'Передумал', 'expensive' => 'Дорого', 'not_available' => 'Нет в наличии', 'other' => 'Другое'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'taken_at' => 'datetime', 'closed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
