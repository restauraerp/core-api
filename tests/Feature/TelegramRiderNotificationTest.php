<?php

namespace Tests\Feature;

use App\Jobs\Telegram\SendTelegramText;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramBot;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Riders notified over the restaurant's own Telegram bot.
 *
 * Telegram is faked with one callback registered in setUp (a second
 * Http::fake() would never be reached - see AGENTS.md); tests steer it through
 * $failSend. Every Bot API call is recorded in $calls by method name.
 */
class TelegramRiderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAEabcdefghijklmnopqrstuvwxyz012345';

    private Tenant $tenant;

    private User $owner;

    private User $rider;

    /** @var list<array{method: string, params: array}> */
    private array $calls = [];

    /** Error sendMessage answers with, or null for success. */
    private ?array $failSend = null;

    private int $nextMessageId = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['app.url' => 'https://api.restaurant.test', 'telegram.per_second' => 100, 'telegram.per_minute' => 1000]);

        Http::fake(function (HttpRequest $request) {
            if (! str_starts_with($request->url(), 'https://api.telegram.org/bot')) {
                return Http::response([], 404);
            }

            $method = substr($request->url(), strrpos($request->url(), '/') + 1);
            $this->calls[] = ['method' => $method, 'params' => $request->data()];

            if (str_contains($request->url(), '/botBAD')) {
                return Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
            }

            return match ($method) {
                'getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'first_name' => 'Bistro Riders', 'username' => 'bistro_riders_bot']]),
                'sendMessage' => $this->failSend
                    ? Http::response(['ok' => false] + $this->failSend, $this->failSend['error_code'])
                    : Http::response(['ok' => true, 'result' => ['message_id' => $this->nextMessageId++]]),
                default => Http::response(['ok' => true, 'result' => true]),
            };
        });

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Bangla Bistro',
            'slug' => 'bistro',
            'plan' => 'growth',
            'status' => 'active',
        ]);

        [$this->owner, $this->rider] = $this->inTenant(function () {
            $owner = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $owner->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            $rider = User::factory()->create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Karim Rider']);
            $rider->assignRole('rider');

            return [$owner, $rider];
        });
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function asOwner(): static
    {
        Sanctum::actingAs($this->owner);

        return $this->withHeader('X-Tenant-ID', $this->tenant->slug);
    }

    /** @return list<array> the recorded calls to one Bot API method */
    private function sent(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    private function connectBot(): TelegramBot
    {
        $this->asOwner()->putJson('/api/v1/integrations/telegram', ['token' => self::TOKEN])->assertOk();

        return TelegramBot::withoutGlobalScopes()->firstOrFail();
    }

    private function webhook(TelegramBot $bot, array $update, ?string $secret = null): TestResponse
    {
        return $this->postJson("/api/v1/telegram/webhook/{$bot->id}", $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? $bot->webhook_secret,
        ]);
    }

    /** Onboards the rider as Telegram chat 777. */
    private function linkRider(TelegramBot $bot, string $chatId = '777'): void
    {
        $link = $this->asOwner()->postJson("/api/v1/integrations/telegram/riders/{$this->rider->id}/link")
            ->assertCreated()
            ->json('link');

        $token = substr($link, strpos($link, 'start=') + 6);

        $this->webhook($bot, ['message' => [
            'chat' => ['id' => (int) $chatId, 'type' => 'private'],
            'from' => ['id' => (int) $chatId, 'username' => 'karim'],
            'text' => '/start '.$token,
        ]])->assertOk();
    }

    private function deliveryOrder(): Order
    {
        return $this->inTenant(function () {
            $customer = Customer::create(['name' => 'Rahima Begum', 'phone' => '01711223344']);
            $product = Product::create(['name' => 'Kacchi Biryani', 'price' => 450, 'type' => 'food']);
            $order = Order::create([
                'location_id' => Location::query()->firstOrFail()->id,
                'customer_id' => $customer->id,
                'order_type' => 'delivery',
                'status' => 'packed',
                'payment_status' => 'unpaid',
                'subtotal' => 900,
                'total' => 900,
                'delivery_address' => 'House 12, Road 5, Dhanmondi',
            ]);
            $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 450]);

            return $order;
        });
    }

    private function assign(Order $order, ?User $rider = null): Delivery
    {
        $id = $this->asOwner()->postJson('/api/v1/deliveries', [
            'order_id' => $order->id,
            'rider_id' => ($rider ?? $this->rider)->id,
            'address' => $order->delivery_address,
            'status' => 'Pending',
        ])->assertCreated()->json('id');

        return $this->inTenant(fn () => Delivery::findOrFail($id));
    }

    #[Test]
    public function connecting_a_bot_checks_the_token_and_registers_the_webhook(): void
    {
        $bot = $this->connectBot();

        $this->assertSame('bistro_riders_bot', $bot->username);
        $this->assertSame(self::TOKEN, $bot->token);
        // Encrypted at rest.
        $this->assertStringNotContainsString(self::TOKEN, (string) \DB::table('telegram_bots')->value('token'));

        $webhook = $this->sent('setWebhook')[0]['params'];
        $this->assertSame("https://api.restaurant.test/api/v1/telegram/webhook/{$bot->id}", $webhook['url']);
        $this->assertSame($bot->webhook_secret, $webhook['secret_token']);

        $this->asOwner()->getJson('/api/v1/integrations/telegram')
            ->assertOk()
            ->assertJsonPath('bot.username', 'bistro_riders_bot')
            ->assertJsonMissingPath('bot.token')
            ->assertJsonPath('riders.0.name', 'Karim Rider')
            ->assertJsonPath('riders.0.linked', false);
    }

    #[Test]
    public function a_token_telegram_rejects_is_not_saved(): void
    {
        $this->asOwner()->putJson('/api/v1/integrations/telegram', ['token' => 'BAD'])
            ->assertUnprocessable();

        $this->asOwner()->putJson('/api/v1/integrations/telegram', ['token' => 'not a token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertSame(0, TelegramBot::withoutGlobalScopes()->count());
    }

    #[Test]
    public function only_the_owner_manages_the_integration(): void
    {
        Sanctum::actingAs($this->rider);

        $this->withHeader('X-Tenant-ID', $this->tenant->slug)
            ->getJson('/api/v1/integrations/telegram')
            ->assertForbidden();
    }

    #[Test]
    public function without_the_delivery_module_there_is_no_integration(): void
    {
        $this->tenant->update(['plan' => 'starter']);

        $this->asOwner()->getJson('/api/v1/integrations/telegram')
            ->assertForbidden()
            ->assertJsonPath('error', 'module_not_in_plan');
    }

    #[Test]
    public function a_rider_is_onboarded_by_their_own_single_use_link(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);

        $rider = $this->rider->fresh();
        $this->assertSame('777', $rider->telegram_chat_id);
        $this->assertSame('karim', $rider->telegram_username);
        $this->assertNotNull($rider->telegram_linked_at);

        // The bot confirms, naming the rider.
        $this->assertStringContainsString('Karim Rider', end($this->calls)['params']['text']);

        $this->asOwner()->getJson('/api/v1/integrations/telegram')
            ->assertJsonPath('riders.0.linked', true)
            ->assertJsonPath('riders.0.telegram_username', 'karim');
    }

    #[Test]
    public function a_used_link_cannot_link_anybody_else(): void
    {
        $bot = $this->connectBot();
        $link = $this->asOwner()->postJson("/api/v1/integrations/telegram/riders/{$this->rider->id}/link")->json('link');
        $start = '/start '.substr($link, strpos($link, 'start=') + 6);

        $this->webhook($bot, ['message' => ['chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777], 'text' => $start]]);
        $this->webhook($bot, ['message' => ['chat' => ['id' => 999, 'type' => 'private'], 'from' => ['id' => 999], 'text' => $start]]);

        $this->assertSame('777', $this->rider->fresh()->telegram_chat_id);
        $this->assertStringContainsString('expired or was already used', end($this->calls)['params']['text']);
    }

    #[Test]
    public function the_webhook_refuses_a_caller_without_the_secret(): void
    {
        $bot = $this->connectBot();

        $this->webhook($bot, ['message' => ['text' => '/start x']], 'wrong-secret')->assertForbidden();
    }

    #[Test]
    public function assigning_a_rider_sends_them_the_delivery(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $bot->forceFill(['last_error' => 'An old failure'])->save();

        $delivery = $this->assign($this->deliveryOrder());

        // A successful send clears the warning the owner was shown.
        $this->assertNull($bot->fresh()->last_error);

        $message = $this->sent('sendMessage');
        $message = end($message)['params'];

        $this->assertSame('777', (string) $message['chat_id']);
        $this->assertTrue($message['protect_content']);
        $this->assertStringContainsString('Rahima Begum', $message['text']);
        $this->assertStringContainsString('01711223344', $message['text']);
        $this->assertStringContainsString('Dhanmondi', $message['text']);
        $this->assertStringContainsString('2 × Kacchi Biryani', $message['text']);
        $this->assertStringContainsString('Collect', $message['text']);

        $buttons = collect($message['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertTrue($buttons->contains('callback_data', "dlv:{$delivery->id}"));
        $this->assertTrue($buttons->contains(fn ($b) => str_contains($b['url'] ?? '', 'google.com/maps')));

        $this->assertNotNull($delivery->fresh()->telegram_message_id);
    }

    #[Test]
    public function the_delivered_button_closes_the_delivery_and_removes_the_contact_details(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $order = $this->deliveryOrder();
        $delivery = $this->assign($order);

        $this->webhook($bot, ['callback_query' => [
            'id' => 'cb-1',
            'from' => ['id' => 777],
            'data' => "dlv:{$delivery->id}",
        ]])->assertOk();

        $delivery = $delivery->fresh();
        $this->assertSame('Delivered', $delivery->status);
        $this->assertNotNull($delivery->telegram_redacted_at);
        $this->assertSame('delivered', $this->inTenant(fn () => $order->fresh()->status));

        $this->assertSame('cb-1', $this->sent('answerCallbackQuery')[0]['params']['callback_query_id']);

        $edit = $this->sent('editMessageText')[0]['params'];
        $this->assertSame($delivery->telegram_message_id, $edit['message_id']);
        $this->assertStringContainsString('Rahima Begum', $edit['text']);
        $this->assertStringNotContainsString('01711223344', $edit['text']);
        $this->assertStringNotContainsString('Dhanmondi', $edit['text']);
        $this->assertArrayNotHasKey('reply_markup', $edit);
    }

    #[Test]
    public function only_the_assigned_rider_can_press_delivered(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $delivery = $this->assign($this->deliveryOrder());

        $this->webhook($bot, ['callback_query' => ['id' => 'cb-2', 'from' => ['id' => 555], 'data' => "dlv:{$delivery->id}"]])
            ->assertOk();

        $this->assertSame('Pending', $delivery->fresh()->status);
        $this->assertSame([], $this->sent('editMessageText'));
    }

    #[Test]
    public function marking_it_delivered_on_the_board_also_scrubs_the_chat(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $delivery = $this->assign($this->deliveryOrder());

        $this->asOwner()->putJson("/api/v1/deliveries/{$delivery->id}", ['status' => 'Delivered'])->assertOk();

        $this->assertStringNotContainsString('01711223344', $this->sent('editMessageText')[0]['params']['text']);
    }

    #[Test]
    public function reassigning_moves_the_details_to_the_new_rider(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $delivery = $this->assign($this->deliveryOrder());
        $firstMessage = $delivery->telegram_message_id;

        $second = $this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Second Rider']);
            $user->assignRole('rider');
            $user->forceFill(['telegram_chat_id' => '888'])->save();

            return $user;
        });

        $this->asOwner()->putJson("/api/v1/deliveries/{$delivery->id}", ['rider_id' => $second->id])->assertOk();

        $edit = $this->sent('editMessageText')[0]['params'];
        $this->assertSame('777', (string) $edit['chat_id']);
        $this->assertSame($firstMessage, $edit['message_id']);
        $this->assertStringContainsString('another rider', $edit['text']);
        $this->assertStringNotContainsString('01711223344', $edit['text']);

        $sends = $this->sent('sendMessage');
        $this->assertSame('888', (string) end($sends)['params']['chat_id']);
    }

    #[Test]
    public function a_rider_who_blocked_the_bot_is_unlinked(): void
    {
        $bot = $this->connectBot();
        $this->linkRider($bot);
        $this->failSend = ['error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'];

        $this->assign($this->deliveryOrder());

        $this->assertFalse($this->rider->fresh()->hasTelegram());
    }

    #[Test]
    public function nothing_is_sent_for_a_restaurant_without_a_bot(): void
    {
        $this->inTenant(fn () => $this->rider->forceFill(['telegram_chat_id' => '777'])->save());

        $this->assign($this->deliveryOrder());

        $this->assertSame([], $this->sent('sendMessage'));
    }

    #[Test]
    public function each_bot_is_held_to_its_per_second_and_per_minute_limits(): void
    {
        $this->connectBot();
        config(['telegram.per_second' => 1, 'telegram.per_minute' => 30]);
        $tenantId = (int) $this->tenant->getKey();

        $this->travelTo(now()->startOfMinute());

        // Two in the same second: the second is held back.
        SendTelegramText::dispatch($tenantId, '777', 'one');
        SendTelegramText::dispatch($tenantId, '777', 'two');
        $this->assertCount(1, $this->sent('sendMessage'));

        // One a second after that, up to 30 in the minute.
        for ($second = 1; $second <= 40; $second++) {
            $this->travel(1)->seconds();
            if (now()->second === 0) {
                break;
            }
            SendTelegramText::dispatch($tenantId, '777', 'tick');
        }

        $this->assertCount(30, $this->sent('sendMessage'));
    }
}
