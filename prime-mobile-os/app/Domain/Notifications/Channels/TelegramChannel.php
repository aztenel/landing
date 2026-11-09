<?php

namespace App\Domain\Notifications\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Bot API (sendMessage). Off unless TELEGRAM_BOT_TOKEN is set in .env (config services.telegram.bot_token);
 * the token is never stored in the database or logged. Telegram is a service outside Kazakhstan, so only the firm's
 * figures go there (TZ 06 §4.2 p.1): sanitize() removes phone numbers, IMEI and IIN as a last line of defence.
 */
class TelegramChannel
{
    public const API = 'https://api.telegram.org';

    public function isEnabled(): bool
    {
        return trim((string) config('services.telegram.bot_token')) !== '';
    }

    public function botUsername(): ?string
    {
        $name = trim((string) config('services.telegram.bot_username'));

        return $name === '' ? null : ltrim($name, '@');
    }

    /** @return bool true when Telegram accepted the message */
    public function send(string $chatId, string $text): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        try {
            $response = Http::timeout(5)->connectTimeout(3)->asJson()
                ->post(self::API.'/bot'.config('services.telegram.bot_token').'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => mb_substr(self::sanitize($text), 0, 4000),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable $e) {
            Log::warning('telegram: send failed', ['error' => $e::class]);

            return false;
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('telegram: rejected', ['status' => $response->status(), 'description' => $response->json('description')]);

            return false;
        }

        return true;
    }

    /** Strip personal data: +7 phone numbers, 15-digit IMEI, 12-digit IIN (TZ 06 §4.2 p.1, TZ 00-kazakhstan п. 2). */
    public static function sanitize(string $text): string
    {
        $text = preg_replace('/\+?[78][\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', '•••', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\d[\s]?){14}\d(?!\d)/u', '•••', $text) ?? $text;   // IMEI (also «35 693812 4471 206»)
        $text = preg_replace('/(?<!\d)\d{12}(?!\d)/u', '•••', $text) ?? $text;               // IIN / BIN

        return $text;
    }
}
