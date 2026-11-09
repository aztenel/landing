<?php

namespace App\Domain\Notifications\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Telegram chat of a user in an organization (Э26 «Привязка Telegram»: «Подключить» → бот → /start <token>). */
class TelegramLink extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['link_token'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'token_expires_at' => 'datetime'];
    }

    public function isLinked(): bool
    {
        return $this->chat_id !== null && $this->linked_at !== null;
    }
}
