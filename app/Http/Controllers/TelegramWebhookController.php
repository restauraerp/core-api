<?php

namespace App\Http\Controllers;

use App\Jobs\Telegram\SendTelegramText;
use App\Models\Delivery;
use App\Models\TelegramBot;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\Branding\RestaurantBranding;
use App\Support\Orders\OrderFlow;
use App\Support\Telegram\DeliveryMessage;
use App\Support\Telegram\DeliveryStatus;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramError;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /telegram/webhook/{bot} - what a restaurant's bot hears.
 *
 * Two things, and everything else is ignored:
 *
 *   - `/start <token>` from a rider opening their onboarding link: links that
 *     Telegram account to the rider it was issued for;
 *   - a tap on a message's Delivered button: marks the delivery delivered.
 *
 * Public, because Telegram is the caller. It proves itself with the secret
 * the bot was registered with, sent back in X-Telegram-Bot-Api-Secret-Token.
 * Always answers 200 once that checks out - anything else makes Telegram
 * retry the same update.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, int $bot): JsonResponse
    {
        $record = TelegramBot::withoutGlobalScopes()->with('tenant')->find($bot);
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($record === null || $record->tenant === null || ! hash_equals($record->webhook_secret, $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->all();

        $this->context->runFor($record->tenant, function () use ($record, $update) {
            if (isset($update['callback_query'])) {
                $this->onButton($record, (array) $update['callback_query']);
            } elseif (isset($update['message'])) {
                $this->onMessage($record, (array) $update['message']);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** @param  array<string, mixed>  $message */
    private function onMessage(TelegramBot $bot, array $message): void
    {
        // Private chats only: a bot added to a group must not link the group.
        if (($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (! str_starts_with($text, '/start')) {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $payload = trim(substr($text, strlen('/start')));
        $restaurant = e(RestaurantBranding::current()['name'] ?? $bot->tenant->name);

        if ($payload === '') {
            $rider = User::where('telegram_chat_id', $chatId)->first();

            $this->reply($chatId, $rider
                ? "You're connected to <b>{$restaurant}</b> as <b>".e($rider->name).'</b>. New deliveries assigned to you will arrive here.'
                : "Hello! To get delivery notifications from <b>{$restaurant}</b>, open the link the restaurant sent you.");

            return;
        }

        $link = TelegramLinkToken::findUsable($payload);
        $rider = $link?->user;

        if ($rider === null) {
            $this->reply($chatId, 'This link has expired or was already used. Ask the restaurant for a new one.');

            return;
        }

        DB::transaction(function () use ($link, $rider, $chatId, $message) {
            // One Telegram account, one rider, per restaurant.
            User::where('telegram_chat_id', $chatId)->whereKeyNot($rider->getKey())->get()
                ->each(fn (User $other) => $other->unlinkTelegram());

            $rider->forceFill([
                'telegram_chat_id' => $chatId,
                'telegram_username' => $message['from']['username'] ?? null,
                'telegram_linked_at' => now(),
            ])->save();

            $link->forceFill(['used_at' => now()])->save();
        });

        $this->reply($chatId, "✅ You're connected to <b>{$restaurant}</b> as <b>".e($rider->name).'</b>. '
            .'Deliveries assigned to you will arrive here - tap <b>Delivered</b> on each one when you hand it over.');
    }

    /**
     * The Delivered button. Only the rider the delivery is assigned to, from
     * the chat it was sent to, can press it.
     *
     * @param  array<string, mixed>  $callback
     */
    private function onButton(TelegramBot $bot, array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $fromChat = (string) ($callback['from']['id'] ?? '');

        if (! preg_match('/^'.DeliveryMessage::DELIVERED_ACTION.':(\d+)$/', $data, $m)) {
            $this->answer($bot, $callback, '');

            return;
        }

        $delivery = Delivery::with(['rider', 'order'])->find((int) $m[1]);

        if ($delivery === null || $delivery->rider === null || $delivery->rider->telegram_chat_id !== $fromChat) {
            $this->answer($bot, $callback, 'This delivery is no longer assigned to you.');

            return;
        }

        if (DeliveryStatus::isClosed($delivery->status)) {
            $this->answer($bot, $callback, 'Already marked as '.strtolower((string) $delivery->status).'.');

            return;
        }

        DB::transaction(function () use ($delivery) {
            // The observer edits the message down to the customer's name.
            $delivery->update(['status' => DeliveryStatus::DELIVERED]);
            $this->advanceOrder($delivery);
        });

        $this->answer($bot, $callback, 'Marked as delivered. Thank you!');
    }

    /**
     * Walks the order along its own flow to delivered - packed, picked up,
     * delivered - one allowed step at a time. An order still in the kitchen
     * is left where it is: the flow says it cannot be delivered yet, and the
     * delivery board records what the rider reported.
     */
    private function advanceOrder(Delivery $delivery): void
    {
        $order = $delivery->order;

        if ($order === null) {
            return;
        }

        $flow = app(OrderFlow::class);
        $status = $order->status;

        foreach ([OrderFlow::PICKED_UP, OrderFlow::DELIVERED] as $step) {
            if ($flow->canTransition((string) $order->order_type, $status, $step, (bool) $order->needs_cooking)) {
                $status = $step;
            }
        }

        if ($status !== $order->status) {
            $order->update(['status' => $status]);
        }
    }

    private function reply(string $chatId, string $html): void
    {
        SendTelegramText::dispatch((int) $this->context->id(), $chatId, $html);
    }

    /**
     * Answered straight away rather than queued: it is not a chat message,
     * and until it is answered the rider's button shows a spinner.
     *
     * @param  array<string, mixed>  $callback
     */
    private function answer(TelegramBot $bot, array $callback, string $text): void
    {
        try {
            (new TelegramClient($bot->token))->answerCallbackQuery((string) ($callback['id'] ?? ''), $text);
        } catch (TelegramError $e) {
            Log::info('Telegram callback answer failed: '.$e->getMessage());
        }
    }
}
