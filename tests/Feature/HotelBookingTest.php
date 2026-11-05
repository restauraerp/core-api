<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Package;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Modules;
use App\Support\Billing\Plans;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hotel rooms: room types and rooms, availability night by night, booking
 * requests from the website with a phone number, and the front desk.
 */
class HotelBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private RoomType $deluxe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $package = Package::create(['name' => 'Restaurant & hotel', 'modules' => Modules::ALL, 'outlets' => 3]);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Bistro Inn', 'slug' => 'inn', 'plan' => 'growth', 'status' => 'active', 'package_id' => $package->id,
        ]);
        $this->location = $this->inTenant(fn () => Location::query()->firstOrFail());
        $this->actAs(RoleDefinitions::RESTAURANT_ADMIN);

        $id = $this->postJson('/api/v1/hotel/room-types', [
            'location_id' => $this->location->id, 'name' => 'Deluxe Double', 'max_adults' => 2, 'max_children' => 1,
            'rate' => 5000, 'weekend_rate' => 6500, 'beds' => '1 queen', 'amenities' => ['AC', 'Wi-Fi'],
        ])->assertCreated()->json('id');
        $this->postJson("/api/v1/hotel/room-types/{$id}/rooms", ['numbers' => '101-102', 'floor' => '1'])
            ->assertCreated()->assertJsonCount(2, 'rooms');
        $this->deluxe = RoomType::withoutGlobalScopes()->findOrFail($id);
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actAs(string $role): void
    {
        Sanctum::actingAs($this->inTenant(function () use ($role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole($role);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'inn');
    }

    /** A Monday some weeks ahead, so weekday and weekend nights are predictable. */
    private function monday(int $weeks = 3): Carbon
    {
        return now(config('app.timezone'))->startOfWeek()->addWeeks($weeks);
    }

    private function stay(string $in, string $out, array $o = []): array
    {
        return array_merge([
            'room_type_id' => $this->deluxe->id, 'check_in' => $in, 'check_out' => $out,
            'adults' => 2, 'name' => 'Karim', 'phone' => '01711223344',
        ], $o);
    }

    #[Test]
    public function the_website_lists_rooms_and_whats_free_for_some_dates(): void
    {
        $mon = $this->monday();
        $this->getJson('/api/v1/site/inn/rooms')
            ->assertOk()
            ->assertJsonPath('room_types.0.name', 'Deluxe Double')
            ->assertJsonMissingPath('room_types.0.rooms');

        // Thursday to Sunday: Thursday 5,000, Friday and Saturday 6,500.
        $this->getJson('/api/v1/site/inn/rooms?check_in='.$mon->copy()->addDays(3)->format('Y-m-d').'&check_out='.$mon->copy()->addDays(6)->format('Y-m-d').'&adults=2')
            ->assertJsonPath('room_types.0.available', true)
            ->assertJsonPath('room_types.0.price.nights', 3)
            ->assertJsonPath('room_types.0.price.total', 18000);

        $this->getJson('/api/v1/site/inn/rooms?check_in='.$mon->format('Y-m-d').'&check_out='.$mon->copy()->addDay()->format('Y-m-d').'&adults=4')
            ->assertJsonPath('room_types.0.available', false)
            ->assertJsonPath('room_types.0.fits', false);
    }

    #[Test]
    public function a_guest_requests_a_room_with_their_phone_number(): void
    {
        $mon = $this->monday();
        $request = fn (array $o = []) => $this->postJson('/api/v1/site/inn/room-requests', array_merge([
            'name' => 'Karim', 'phone' => '01711223344', 'room_type_id' => $this->deluxe->id,
            'check_in' => $mon->format('Y-m-d'), 'check_out' => $mon->copy()->addDays(2)->format('Y-m-d'), 'adults' => 2,
        ], $o));

        $request(['phone' => null])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $request(['adults' => 3])->assertUnprocessable()->assertJsonValidationErrors('adults');
        $request(['check_in' => now()->subDay()->format('Y-m-d')])->assertUnprocessable();

        // No email needed - the phone is how the hotel reaches the guest.
        $id = $request()->assertCreated()->assertJsonPath('nights', 2)->assertJsonPath('total', 10000)->json('request_id');

        $this->getJson('/api/v1/hotel/bookings?view=pending')
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.guest_phone', '+8801711223344')
            ->assertJsonPath('data.0.source', 'website');
    }

    #[Test]
    public function rooms_are_never_double_booked(): void
    {
        $mon = $this->monday();
        $in = $mon->format('Y-m-d');
        $out = $mon->copy()->addDays(3)->format('Y-m-d');

        // Two rooms: two confirmed stays fit, a third does not.
        $this->postJson('/api/v1/hotel/bookings', $this->stay($in, $out))->assertCreated()->assertJsonPath('status', 'Confirmed');
        $this->postJson('/api/v1/hotel/bookings', $this->stay($in, $out, ['phone' => '01811223344']))->assertCreated();
        $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->copy()->addDay()->format('Y-m-d'), $out, ['phone' => '01911223344']))
            ->assertUnprocessable()->assertJsonValidationErrors('room_type_id');

        // The website will not take a request for a full night either.
        $this->postJson('/api/v1/site/inn/room-requests', [
            'name' => 'X', 'phone' => '01611223344', 'room_type_id' => $this->deluxe->id,
            'check_in' => $mon->copy()->addDays(2)->format('Y-m-d'), 'check_out' => $mon->copy()->addDays(4)->format('Y-m-d'), 'adults' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('check_in');

        // The check-out day is free for the next guest.
        $this->postJson('/api/v1/hotel/bookings', $this->stay($out, $mon->copy()->addDays(4)->format('Y-m-d'), ['phone' => '01511223344']))->assertCreated();

        $this->getJson('/api/v1/hotel/availability?from='.$in.'&days=4')
            ->assertJsonPath('types.0.nights.'.$in.'.free', 0)
            ->assertJsonPath('types.0.nights.'.$out.'.free', 1);
    }

    #[Test]
    public function the_front_desk_confirms_checks_in_to_a_room_and_out(): void
    {
        $mon = $this->monday();
        $id = $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->format('Y-m-d'), $mon->copy()->addDays(2)->format('Y-m-d'), ['status' => 'Pending']))
            ->assertCreated()->json('id');
        $other = $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->format('Y-m-d'), $mon->copy()->addDays(1)->format('Y-m-d'), ['phone' => '01811223344']))
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Checked in'])->assertUnprocessable();
        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Confirmed'])->assertOk();

        $rooms = $this->getJson("/api/v1/hotel/bookings/{$id}/rooms")->assertOk()->json('rooms');
        $this->assertCount(2, $rooms);
        $this->postJson("/api/v1/hotel/bookings/{$other}/room", ['room_id' => $rooms[0]['id']])->assertOk()->assertJsonPath('room.number', '101');

        // 101 is taken that night; 102 is not.
        $this->assertCount(1, $this->getJson("/api/v1/hotel/bookings/{$id}/rooms")->json('rooms'));
        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Checked in', 'room_id' => $rooms[0]['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Checked in'])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Checked in', 'room_id' => $rooms[1]['id']])
            ->assertOk()->assertJsonPath('status', 'Checked in')->assertJsonPath('room.number', '102');

        $this->getJson('/api/v1/hotel/bookings?view=in_house')->assertJsonPath('counts.in_house', 1);

        // Paid in part, then the rest at check-out.
        $this->postJson("/api/v1/hotel/bookings/{$id}/payments", ['amount' => 4000, 'method' => 'bkash'])->assertCreated()->assertJsonPath('due', 6000);
        $this->postJson("/api/v1/hotel/bookings/{$id}/payments", ['amount' => 9000, 'method' => 'cash'])->assertUnprocessable();
        $this->postJson("/api/v1/hotel/bookings/{$id}/payments", ['amount' => 6000, 'method' => 'cash'])->assertJsonPath('due', 0);
        $this->postJson("/api/v1/hotel/bookings/{$id}/status", ['status' => 'Checked out'])->assertOk()->assertJsonPath('checked_out_at', fn ($v) => $v !== null);

        $this->getJson('/api/v1/reports/profit')->assertJsonPath('summary.room_income', 10000);
    }

    #[Test]
    public function weekend_nights_use_the_weekend_rate_and_the_agreed_rate_is_kept(): void
    {
        $mon = $this->monday();
        $this->postJson('/api/v1/hotel/bookings/quote', $this->stay($mon->copy()->addDays(4)->format('Y-m-d'), $mon->copy()->addDays(7)->format('Y-m-d')))
            ->assertJsonPath('price.nightly_rates.0.rate', 6500)   // Friday
            ->assertJsonPath('price.nightly_rates.1.rate', 6500)   // Saturday
            ->assertJsonPath('price.nightly_rates.2.rate', 5000)   // Sunday
            ->assertJsonPath('price.total', 18000);

        $id = $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->format('Y-m-d'), $mon->copy()->addDays(2)->format('Y-m-d')))->json('id');
        $this->putJson("/api/v1/hotel/room-types/{$this->deluxe->id}", ['rate' => 9000])->assertOk();
        $this->getJson("/api/v1/hotel/bookings/{$id}")->assertJsonPath('total', 10000);
    }

    #[Test]
    public function rooms_can_be_added_in_ranges_and_taken_out_of_service(): void
    {
        $type = $this->postJson("/api/v1/hotel/room-types/{$this->deluxe->id}/rooms", ['numbers' => '103, 104, 101'])->assertCreated();
        $this->assertSame(['101', '102', '103', '104'], array_column($type->json('rooms'), 'number'));
        $this->postJson("/api/v1/hotel/room-types/{$this->deluxe->id}/rooms", ['numbers' => '<b>'])->assertUnprocessable();

        $room = $type->json('rooms.3.id');
        $this->putJson("/api/v1/hotel/rooms/{$room}", ['in_service' => false])->assertOk();
        $mon = $this->monday();
        $this->getJson('/api/v1/hotel/availability?from='.$mon->format('Y-m-d').'&days=1')->assertJsonPath('types.0.rooms', 3);

        $this->putJson("/api/v1/hotel/room-types/{$this->deluxe->id}", ['photos' => ['https://evil.example/x.jpg']])->assertUnprocessable();
    }

    #[Test]
    public function without_the_hotel_module_there_are_no_rooms(): void
    {
        $this->tenant->update(['package_id' => null, 'plan' => 'growth']);
        // A fresh sign-in: the earlier user carries the tenant as it was.
        $this->actAs(RoleDefinitions::RESTAURANT_ADMIN);

        $this->getJson('/api/v1/hotel/room-types')->assertForbidden()->assertJsonPath('error', 'module_not_in_plan');
        $this->getJson('/api/v1/site/inn/rooms')->assertNotFound();
    }

    #[Test]
    public function the_growth_plan_does_not_include_the_hotel_add_on(): void
    {
        $this->assertNotContains(Modules::HOTEL, Plans::modules('growth'));
        $this->assertContains(Modules::HOTEL, Modules::ALL);
    }

    #[Test]
    public function a_confirmed_guest_with_an_email_is_emailed_and_one_without_is_not(): void
    {
        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'secret']);
        Http::fake(['website.test/*' => Http::response(['status' => 'queued'])]);
        $mon = $this->monday();

        $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->format('Y-m-d'), $mon->copy()->addDay()->format('Y-m-d')))->assertCreated();
        Http::assertNothingSent();

        $this->postJson('/api/v1/hotel/bookings', $this->stay($mon->format('Y-m-d'), $mon->copy()->addDay()->format('Y-m-d'), ['phone' => '01811223344', 'email' => 'guest@example.com']))->assertCreated();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/notifications/room-booking')
            && $r['kind'] === 'confirmed' && $r['guest']['email'] === 'guest@example.com' && $r['stay']['nights'] === 1);
    }
}
