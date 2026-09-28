<?php

namespace App\Support\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The few Bot API calls the rider notifications need, over plain HTTPS.
 *
 * No SDK: every call is one POST to https://api.telegram.org/bot<token>/<method>
 * answering {ok, result} or {ok: false, error_code, description, parameters}.
 * Failures throw TelegramError carrying Telegram's own code.
 */
class TelegramClient
{
    public function __construct(private readonly string $token) {}

    /** @return array<string, mixed> the bot's own user record */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            // Only what the bot acts on: /start from a rider, and the
            // Delivered button.
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    /**
     * @param  array<string, mixed>  $options  reply_markup etc.
     * @return array<string, mixed> the sent message
     */
    public function sendMessage(string $chatId, string $html, array $options = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ], $options));
    }

    /** @param  array<string, mixed>  $options */
    public function editMessageText(string $chatId, int $messageId, string $html, array $options = []): void
    {
        $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ], $options));
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return mixed Telegram's `result`
     */
    private function call(string $method, array $params = []): mixed
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->post('https://api.telegram.org/bot'.$this->token.'/'.$method, $params);
        } catch (ConnectionException $e) {
            // The token is in the URL, and connection errors quote the URL.
            throw new TelegramError('Telegram could not be reached: '.str_replace($this->token, '<token>', $e->getMessage()));
        }

        $body = (array) $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            return $body['result'] ?? null;
        }

        throw new TelegramError(
            (string) ($body['description'] ?? 'Telegram answered '.$response->status()),
            (int) ($body['error_code'] ?? $response->status()),
            isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null,
        );
    }
}
