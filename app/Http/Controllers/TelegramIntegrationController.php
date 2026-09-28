<?php

namespace App\Http\Controllers;

use App\Jobs\Telegram\SendTelegramText;
use App\Models\TelegramBot;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\Branding\RestaurantBranding;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramError;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Integration > Telegram: the restaurant's own bot, and its riders' links.
 *
 * The owner makes a bot with @BotFather and pastes its token here. From then
 * on each rider is onboarded with a single-use link copied from this screen;
 * once they tap Start, deliveries assigned to them arrive in their chat.
 *
 * Behind module:delivery and manage_integrations: without the Delivery module
 * there is nothing to notify a rider about.
 */
class TelegramIntegrationController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $bot = TelegramBot::first();

        // Everyone with the rider role, plus anybody else who has been linked -
        // a manager who also delivers.
        $riders = User::with(['roles', 'location:id,name'])
            ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'rider'))
                ->orWhereNotNull('telegram_chat_id'))
            ->orderBy('name')
            ->get();

        $pending = TelegramLinkToken::whereIn('user_id', $riders->modelKeys())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->pluck('expires_at', 'user_id');

        return response()->json([
            'bot' => $bot === null ? null : $this->presentBot($bot),
            'limits' => [
                'per_second' => (int) config('telegram.per_second'),
                'per_minute' => (int) config('telegram.per_minute'),
            ],
            'riders' => $riders->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'location' => $user->location?->name,
                'linked' => $user->hasTelegram(),
                'telegram_username' => $user->telegram_username,
                'linked_at' => $user->telegram_linked_at?->toIso8601String(),
                'link_expires_at' => isset($pending[$user->id]) ? $pending[$user->id]->toIso8601String() : null,
            ])->values(),
        ]);
    }

    /**
     * Connects a bot, or replaces the connected one.
     *
     * The token is checked with Telegram before anything is saved, and the
     * webhook is registered with a fresh secret. Moving to a different bot
     * clears every rider's link: they started the old bot, and the new one
     * cannot message them until they start it too.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:100', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
        ], [
            'token.regex' => 'That does not look like a bot token. It is the long value @BotFather gives you, like 123456789:AAE...',
        ]);

        $telegram = new TelegramClient($validated['token']);

        try {
            $me = $telegram->getMe();
        } catch (TelegramError $e) {
            throw ValidationException::withMessages([
                'token' => ['Telegram did not accept this token: '.$e->getMessage()],
            ]);
        }

        if (($me['is_bot'] ?? false) !== true || empty($me['username'])) {
            throw ValidationException::withMessages(['token' => ['That token does not belong to a bot.']]);
        }

        $bot = DB::transaction(function () use ($validated, $me) {
            $bot = TelegramBot::first() ?? new TelegramBot;
            $changedBot = $bot->exists && (int) $bot->bot_user_id !== (int) $me['id'];

            $bot->fill([
                'token' => $validated['token'],
                'bot_user_id' => (int) $me['id'],
                'username' => $me['username'],
                'name' => $me['first_name'] ?? null,
                'webhook_secret' => Str::random(48),
                'last_error' => null,
            ])->save();

            if ($changedBot) {
                $this->unlinkAllRiders();
            }

            return $bot;
        });

        $webhookError = null;

        try {
            $telegram->setWebhook($this->webhookUrl($bot), $bot->webhook_secret);
        } catch (TelegramError $e) {
            // Saved regardless, so it can be retried by saving again; the
            // screen shows why it is not receiving yet.
            $webhookError = 'Telegram could not reach this server: '.$e->getMessage();
            $bot->forceFill(['last_error' => $webhookError])->save();
        }

        return response()->json([
            'bot' => $this->presentBot($bot->fresh()),
            'webhook_error' => $webhookError,
        ]);
    }

    /** Disconnects the bot and forgets every rider's link. */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $bot = TelegramBot::first();

        if ($bot !== null) {
            try {
                (new TelegramClient($bot->token))->deleteWebhook();
            } catch (TelegramError) {
                // A revoked token cannot remove its webhook either; the bot
                // is being forgotten anyway.
            }

            DB::transaction(function () use ($bot) {
                $bot->delete();
                $this->unlinkAllRiders();
            });
        }

        return response()->json(null, 204);
    }

    /** A fresh single-use onboarding link for one rider, to copy and send them. */
    public function link(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        $bot = $this->requireBot();
        $token = TelegramLinkToken::issueFor($user);

        return response()->json([
            'link' => $bot->startLink($token),
            'expires_at' => now()->addHours(TelegramLinkToken::TTL_HOURS)->toIso8601String(),
        ], 201);
    }

    /** Stops notifying a rider, and voids any link not yet used. */
    public function unlink(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        TelegramLinkToken::where('user_id', $user->getKey())->whereNull('used_at')->delete();
        $user->unlinkTelegram();

        return response()->json(null, 204);
    }

    /** Sends a linked rider a test message, to confirm it arrives. */
    public function test(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        $this->requireBot();

        if (! $user->hasTelegram()) {
            throw ValidationException::withMessages(['user' => [$user->name.' has not connected Telegram yet.']]);
        }

        $restaurant = e(RestaurantBranding::current()['name'] ?? 'the restaurant');

        SendTelegramText::dispatch((int) $this->context->id(), (string) $user->telegram_chat_id,
            "👋 Test message from <b>{$restaurant}</b>. Delivery notifications are working.");

        return response()->json(['queued' => true], 202);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_integrations')) {
            abort(403, 'Only the restaurant owner can manage integrations.');
        }
    }

    private function requireBot(): TelegramBot
    {
        return TelegramBot::first() ?? throw ValidationException::withMessages([
            'bot' => ['Connect a Telegram bot first.'],
        ]);
    }

    private function unlinkAllRiders(): void
    {
        User::whereNotNull('telegram_chat_id')->get()->each(fn (User $user) => $user->unlinkTelegram());
        TelegramLinkToken::whereNull('used_at')->delete();
    }

    private function webhookUrl(TelegramBot $bot): string
    {
        $base = rtrim((string) (config('telegram.webhook_url') ?: config('app.url')), '/');

        return $base.'/api/v1/telegram/webhook/'.$bot->getKey();
    }

    /** @return array<string, mixed> */
    private function presentBot(TelegramBot $bot): array
    {
        return [
            'username' => $bot->username,
            'name' => $bot->name,
            'link' => 'https://t.me/'.$bot->username,
            'last_error' => $bot->last_error,
            'connected_at' => $bot->created_at?->toIso8601String(),
        ];
    }
}
