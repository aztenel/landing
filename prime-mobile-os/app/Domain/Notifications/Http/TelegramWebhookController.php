<?php

namespace App\Domain\Notifications\Http;

use App\Domain\Notifications\Services\TelegramLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /telegram/webhook — updates from the Telegram bot (set the webhook with secret_token = TELEGRAM_WEBHOOK_SECRET).
 * Without a configured secret the endpoint does not exist (404); a wrong secret is 403. No session, no CSRF.
 */
class TelegramWebhookController
{
    public function __invoke(Request $request, TelegramLinker $linker): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_if($secret === '' || (string) config('services.telegram.bot_token') === '', 404);
        abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $linker->handleUpdate($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
