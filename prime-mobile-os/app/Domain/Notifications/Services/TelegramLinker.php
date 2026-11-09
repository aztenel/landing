<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Channels\TelegramChannel;
use App\Domain\Notifications\Models\TelegramLink;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Str;

/**
 * Linking a user's Telegram chat (Э26 «Подключить» → бот): the screen shows the deep link
 * https://t.me/<bot>?start=<token>; Telegram sends «/start <token>» to the webhook (TelegramWebhookController),
 * the chat id is stored. A token lives 30 minutes and works once.
 */
class TelegramLinker
{
    public function __construct(private readonly TelegramChannel $telegram) {}

    /** Deep link for the current organization and user; null when Telegram is off or the bot name is not set. */
    public function linkUrl(User $user): ?string
    {
        if (! $this->telegram->isEnabled() || ! ($bot = $this->telegram->botUsername())) {
            return null;
        }
        $token = Str::random(40);
        TelegramLink::query()->updateOrCreate(['user_id' => $user->id], ['link_token' => $token, 'token_expires_at' => now()->addMinutes(30)]);

        return "https://t.me/{$bot}?start={$token}";
    }

    /** Integrator / tests: set the chat id directly. */
    public function linkChat(User $user, string $chatId): TelegramLink
    {
        return TelegramLink::query()->updateOrCreate(['user_id' => $user->id], [
            'chat_id' => $chatId, 'linked_at' => now(), 'link_token' => null, 'token_expires_at' => null,
        ]);
    }

    public function unlink(User $user): void
    {
        TelegramLink::query()->where('user_id', $user->id)->update(['chat_id' => null, 'linked_at' => null, 'link_token' => null, 'updated_at' => now()]);
    }

    public function isLinked(User $user): bool
    {
        return (bool) TelegramLink::query()->where('user_id', $user->id)->first()?->isLinked();
    }

    /**
     * Webhook update from Telegram. Only «/start <token>» of a valid token links a chat; everything else is ignored.
     *
     * @return bool linked
     */
    public function handleUpdate(array $update): bool
    {
        $text = trim((string) data_get($update, 'message.text'));
        $chatId = data_get($update, 'message.chat.id');
        if ($chatId === null || ! preg_match('/^\/start\s+([A-Za-z0-9]{20,64})$/', $text, $m)) {
            return false;
        }
        // the token tells us the organization: system lookup without the tenant scope
        $link = TelegramLink::withoutOrganizationScope()->where('link_token', $m[1])->where('token_expires_at', '>', now())->first();
        if (! $link) {
            $this->telegram->send((string) $chatId, __('Ссылка устарела. Откройте «Настройки → Уведомления» и нажмите «Подключить» ещё раз'));

            return false;
        }

        return CurrentOrganization::instance()->run((int) $link->organization_id, function () use ($link, $chatId) {
            $link->forceFill(['chat_id' => (string) $chatId, 'linked_at' => now(), 'link_token' => null, 'token_expires_at' => null])->save();
            activity('settings')->performedOn($link)->causedBy(User::query()->find($link->user_id))->log('telegram_linked');
            $this->telegram->send((string) $chatId, __('Готово: уведомления :org подключены', ['org' => CurrentOrganization::instance()->require()->name]));

            return true;
        });
    }
}
