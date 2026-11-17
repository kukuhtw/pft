<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Bot API client. The bot token is part of the URL, so request URLs and
 * exception messages are never logged (ISO/IEC 27001 A.8.24 / A.5.17).
 */
class TelegramClient
{
    public function configured(): bool
    {
        return (string) config('services.telegram.bot_token') !== '';
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard
     * @return array<string, mixed>|null
     */
    public function sendMessage(int $chatId, string $text, ?array $keyboard = null): ?array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard === null ? null : ['inline_keyboard' => $keyboard],
        ], static fn ($v): bool => $v !== null));
    }

    /** @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard */
    public function editMessage(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $this->call('editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard === null ? null : ['inline_keyboard' => $keyboard],
        ], static fn ($v): bool => $v !== null));
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $text], static fn ($v): bool => $v !== null));
    }

    /** @return array<string, mixed>|null */
    public function call(string $method, array $payload = []): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::asJson()
                ->timeout(8)
                ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method, $payload);
        } catch (\Throwable) {
            Log::warning('telegram.api_unreachable', ['method' => $method]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('telegram.api_error', ['method' => $method, 'status' => $response->status(), 'description' => $response->json('description')]);

            return null;
        }

        return (array) $response->json('result', []) ?: ['ok' => true];
    }
}
