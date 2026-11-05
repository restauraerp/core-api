<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
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
 * Table bookings from the restaurant's side: website requests come in with
 * the guest's phone, staff call and approve or decline, and take bookings of
 * their own.
 */
class ReservationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);
        $this->location = $this->inTenant(fn () => Location::query()->firstOrFail());
        $this->actAs(RoleDefinitions::RESTAURANT_ADMIN);
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actAs(string $role, string $name = 'Staff'): User
    {
        $user = $this->inTenant(function () use ($role, $name) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey(), 'name' => $name]);
            $user->assignRole($role);

            return $user;
        });
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', 'spice');

        return $user;
    }

    /** A request from the website, as a guest makes it. */
    private function websiteRequest(array $overrides = []): int
    {
        return $this->postJson('/api/v1/site/spice/reservations', array_merge([
            'name' => 'Rahim Uddin', 'email' => 'rahim@example.com', 'phone' => '01711223344', 'location_id' => $this->location->id,
            'date' => now()->addDays(2)->format('Y-m-d'), 'time' => '19:30', 'guests' => 4, 'notes' => 'Birthday',
        ], $overrides))->assertCreated()->json('reservation_id');
    }

    #[Test]
    public function a_website_request_arrives_with_the_number_to_call(): void
    {
        $this->postJson('/api/v1/site/spice/reservations', [
            'name' => 'No Phone', 'location_id' => $this->location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '19:00', 'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);

        $id = $this->websiteRequest();

        $this->getJson('/api/v1/reservations?view=requests')
            ->assertOk()
            ->assertJsonPath('counts.requests', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', 'Pending')
            ->assertJsonPath('data.0.guest_name', 'Rahim Uddin')
            ->assertJsonPath('data.0.guest_phone', '+8801711223344')
            ->assertJsonPath('data.0.source', 'website')
            ->assertJsonPath('data.0.time', '19:30')
            ->assertJsonPath('data.0.next', ['Confirmed', 'Declined', 'Cancelled']);
    }

    #[Test]
    public function staff_call_the_guest_then_approve_with_a_table(): void
    {
        $id = $this->websiteRequest();
        $table = $this->inTenant(fn () => Table::create(['location_id' => $this->location->id, 'name' => 'T4', 'capacity' => 4]));
        $this->actAs(RoleDefinitions::RESTAURANT_ADMIN, 'Nadia');

        $this->postJson("/api/v1/reservations/{$id}/called")->assertOk()->assertJsonPath('called_by', 'Nadia');

        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Confirmed', 'table_id' => $table->id])
            ->assertOk()
            ->assertJsonPath('status', 'Confirmed')
            ->assertJsonPath('table.name', 'T4')
            ->assertJsonPath('handled_by', 'Nadia');

        $this->getJson('/api/v1/reservations?view=requests')->assertJsonPath('counts.requests', 0)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reservations?view=upcoming')->assertJsonPath('data.0.id', $id);

        // Then they come, and leave.
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Seated'])->assertOk();
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Completed'])->assertOk();
    }

    #[Test]
    public function a_request_can_be_declined_with_a_reason_and_reopened(): void
    {
        $id = $this->websiteRequest();

        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Declined', 'reason' => 'Fully booked that evening'])
            ->assertOk()
            ->assertJsonPath('status', 'Declined')
            ->assertJsonPath('decline_reason', 'Fully booked that evening')
            ->assertJsonPath('next', ['Pending']);

        // Declined is not seated or confirmed.
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Seated'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Pending'])
            ->assertOk()
            ->assertJsonPath('decline_reason', null)
            ->assertJsonPath('handled_by', null);
    }

    #[Test]
    public function staff_take_a_booking_by_phone(): void
    {
        $this->postJson('/api/v1/reservations', [
            'name' => 'Walk In', 'location_id' => $this->location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $booking = $this->postJson('/api/v1/reservations', [
            'name' => 'Karim', 'phone' => '01811-223344', 'location_id' => $this->location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '13:00', 'guests' => 6, 'notes' => 'Window table',
        ])->assertCreated()
            ->assertJsonPath('status', 'Confirmed')
            ->assertJsonPath('source', 'staff')
            ->assertJsonPath('guest_phone', '+8801811223344')
            ->json();

        // The guest becomes a customer, found by phone next time.
        $this->assertSame(1, $this->inTenant(fn () => Customer::where('phone', '+8801811223344')->count()));
        $this->postJson('/api/v1/reservations', [
            'name' => 'Karim', 'phone' => '+8801811223344', 'location_id' => $this->location->id,
            'date' => now()->addDays(3)->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertCreated();
        $this->assertSame(1, $this->inTenant(fn () => Customer::where('phone', '+8801811223344')->count()));

        // Moved to another evening.
        $this->putJson("/api/v1/reservations/{$booking['id']}", ['time' => '20:15', 'guests' => 8])
            ->assertOk()
            ->assertJsonPath('time', '20:15')
            ->assertJsonPath('guests', 8)
            ->assertJsonPath('date', $booking['date']);

        $this->postJson('/api/v1/reservations', [
            'name' => 'Late', 'phone' => '01911223344', 'location_id' => $this->location->id,
            'date' => now()->subDays(3)->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    #[Test]
    public function search_finds_a_booking_by_name_or_phone(): void
    {
        $this->websiteRequest();
        $this->websiteRequest(['name' => 'Sumi Akter', 'phone' => '01912345678']);

        $this->getJson('/api/v1/reservations?view=all&q=sumi')->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest_name', 'Sumi Akter');
        $this->getJson('/api/v1/reservations?view=all&q=01711-22')->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest_name', 'Rahim Uddin');
    }

    #[Test]
    public function another_restaurants_tables_and_branches_are_refused(): void
    {
        $id = $this->websiteRequest();
        $other = app(TenantProvisioner::class)->create(['name' => 'Rival', 'slug' => 'rival', 'plan' => 'growth', 'status' => 'active']);
        [$theirLocation, $theirTable] = app(TenantContext::class)->runFor($other, function () {
            $location = Location::query()->firstOrFail();

            return [$location, Table::create(['location_id' => $location->id, 'name' => 'X', 'capacity' => 2])];
        });

        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Confirmed', 'table_id' => $theirTable->id])->assertUnprocessable();
        $this->postJson('/api/v1/reservations', [
            'name' => 'X', 'phone' => '01711000000', 'location_id' => $theirLocation->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    #[Test]
    public function front_of_house_can_manage_bookings_and_the_kitchen_cannot(): void
    {
        $id = $this->websiteRequest();

        $this->actAs('pos_manager');
        $this->getJson('/api/v1/reservations')->assertOk();
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Confirmed'])->assertOk();

        $this->actAs('chef');
        $this->getJson('/api/v1/reservations')->assertForbidden();
    }

    #[Test]
    public function the_list_shows_times_in_the_restaurants_timezone(): void
    {
        $id = $this->websiteRequest(['time' => '21:45']);

        $this->getJson("/api/v1/reservations/{$id}")->assertJsonPath('time', '21:45');
        $this->assertSame(Reservation::PENDING, $this->inTenant(fn () => Reservation::find($id)->status));
    }

    #[Test]
    public function the_guest_is_kept_informed_by_email(): void
    {
        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'secret']);
        Http::fake(['website.test/*' => Http::response(['status' => 'queued'])]);
        $sent = fn () => collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/api/notifications/table-booking'))
            ->values();

        $id = $this->websiteRequest(['email' => 'Rahim@Example.com']);
        $this->assertSame('received', $sent()->last()['kind']);
        $this->assertSame('rahim@example.com', $sent()->last()['guest']['email']);
        $this->assertSame(4, $sent()->last()['booking']['guests']);

        $table = $this->inTenant(fn () => Table::create(['location_id' => $this->location->id, 'name' => 'T9', 'capacity' => 4]));
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Confirmed', 'table_id' => $table->id])->assertOk();
        $confirmed = $sent()->last();
        $this->assertSame('confirmed', $confirmed['kind']);
        $this->assertSame('T9', $confirmed['booking']['table']);
        $this->assertSame('19:30', Carbon::parse($confirmed['booking']['time'])->format('H:i'));

        // Seating them is not news to the guest.
        $this->postJson("/api/v1/reservations/{$id}/status", ['status' => 'Seated'])->assertOk();
        $this->assertCount(2, $sent());

        $this->getJson("/api/v1/reservations/{$id}")->assertJsonPath('guest_email', 'rahim@example.com')->assertJsonPath('last_emailed_at', fn ($v) => $v !== null);

        // Declined, with the reason.
        $other = $this->websiteRequest(['email' => 'sumi@example.com', 'phone' => '01912345678']);
        $this->postJson("/api/v1/reservations/{$other}/status", ['status' => 'Declined', 'reason' => 'Fully booked'])->assertOk();
        $this->assertSame('declined', $sent()->last()['kind']);
        $this->assertSame('Fully booked', $sent()->last()['reason']);
    }

    #[Test]
    public function a_staff_booking_without_an_email_sends_nothing(): void
    {
        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'secret']);
        Http::fake();

        $this->postJson('/api/v1/reservations', [
            'name' => 'Walk In', 'phone' => '01811223344', 'location_id' => $this->location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertCreated()->assertJsonPath('guest_email', null);

        Http::assertNothingSent();

        $this->postJson('/api/v1/reservations', [
            'name' => 'Bad', 'phone' => '01811223344', 'email' => 'nope', 'location_id' => $this->location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '13:00', 'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
