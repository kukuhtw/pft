<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Http\Controllers;

use App\Modules\Telegram\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/telegram/webhook. Telegram signs each call with the secret given to setWebhook
 * (X-Telegram-Bot-Api-Secret-Token); anything else is rejected before the body is read.
 * Processing errors still answer 200 so Telegram does not redeliver the same update forever;
 * the message text is never logged.
 */
final class TelegramWebhookController
{
    public function __invoke(Request $request, TelegramBotService $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '' || (string) config('services.telegram.bot_token') === '') {
            abort(404);
        }

        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        try {
            $bot->handle($request->json()->all());
        } catch (\Throwable $e) {
            Log::error('telegram.update_failed', ['update_id' => $request->json('update_id'), 'exception' => $e::class]);
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
