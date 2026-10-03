<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenuePackage;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Venue booking: halls hired for events - priced on the server, never
 * double-booked, paid in advance and in part.
 */
class VenueBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Hall $hall;

    private VenuePackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);
        $this->location = $this->inTenant(fn () => Location::query()->firstOrFail());
        $this->actAs(RoleDefinitions::RESTAURANT_ADMIN);

        $this->hall = Hall::withoutGlobalScopes()->findOrFail($this->postJson('/api/v1/venues', [
            'location_id' => $this->location->id, 'name' => 'Grand Hall', 'capacity' => 200, 'min_guests' => 30,
            'price' => 20000, 'rent_type' => 'per_event', 'buffer_minutes' => 60,
        ])->assertCreated()->json('id'));

        $this->package = VenuePackage::withoutGlobalScopes()->findOrFail($this->postJson('/api/v1/venue-packages', [
            'name' => 'Wedding Platter', 'price_per_person' => 850, 'min_guests' => 50,
        ])->assertCreated()->json('id'));
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actAs(string $role, string $name = 'Staff'): void
    {
        Sanctum::actingAs($this->inTenant(function () use ($role, $name) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey(), 'name' => $name]);
            $user->assignRole($role);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    private function day(int $days = 10): string
    {
        return now()->addDays($days)->format('Y-m-d');
    }

    private function booking(array $overrides = []): array
    {
        return array_merge([
            'hall_id' => $this->hall->id, 'date' => $this->day(), 'start_time' => '18:00', 'end_time' => '22:00',
            'guests' => 100, 'event_type' => 'wedding', 'event_title' => 'Rahim & Sumi',
            'name' => 'Rahim Uddin', 'phone' => '01711223344', 'package_id' => $this->package->id,
            'extras' => [['label' => 'Stage decoration', 'amount' => 15000]], 'discount' => 5000,
        ], $overrides);
    }

    #[Test]
    public function a_booking_is_priced_on_the_server(): void
    {
        // Rent 20,000 + 100 x 850 + decoration 15,000 - 5,000 discount.
        $this->postJson('/api/v1/venue-bookings/quote', $this->booking())
            ->assertOk()
            ->assertJsonPath('price.rent_amount', 20000)
            ->assertJsonPath('price.food_amount', 85000)
            ->assertJsonPath('price.total', 115000)
            ->assertJsonPath('clashes', []);

        $this->postJson('/api/v1/venue-bookings', $this->booking() + ['total' => 1])
            ->assertCreated()
            ->assertJsonPath('total', 115000)
            ->assertJsonPath('status', 'Pending')
            ->assertJsonPath('guest_phone', '+8801711223344')
            ->assertJsonPath('package.name', 'Wedding Platter')
            ->assertJsonPath('due', 115000);
    }

    #[Test]
    public function a_per_hour_hall_charges_by_the_half_hour(): void
    {
        $this->putJson("/api/v1/venues/{$this->hall->id}", ['price' => 3000, 'rent_type' => 'per_hour'])->assertOk();

        // 18:00 - 21:20 is 3h20, charged as 3.5 hours.
        $this->postJson('/api/v1/venue-bookings/quote', $this->booking(['end_time' => '21:20', 'package_id' => null, 'extras' => [], 'discount' => 0]))
            ->assertJsonPath('price.hours', 3.5)
            ->assertJsonPath('price.rent_amount', 10500);
    }

    #[Test]
    public function a_confirmed_booking_holds_the_hall_and_its_cleanup_time(): void
    {
        $this->postJson('/api/v1/venue-bookings', $this->booking(['status' => 'Confirmed']))->assertCreated();

        // Overlapping, and inside the hour kept free after 22:00.
        $this->postJson('/api/v1/venue-bookings', $this->booking(['start_time' => '20:00', 'end_time' => '23:00', 'status' => 'Confirmed']))
            ->assertUnprocessable()->assertJsonValidationErrors('hall_id');
        $this->postJson('/api/v1/venue-bookings', $this->booking(['start_time' => '22:30', 'end_time' => '23:30', 'status' => 'Confirmed']))
            ->assertUnprocessable();

        // After the cleanup hour is free; so is another day.
        $this->postJson('/api/v1/venue-bookings', $this->booking(['start_time' => '23:00', 'end_time' => '23:59', 'status' => 'Confirmed']))->assertCreated();
        $this->postJson('/api/v1/venue-bookings', $this->booking(['date' => $this->day(11), 'status' => 'Confirmed']))->assertCreated();
    }

    #[Test]
    public function a_tentative_booking_is_reported_but_does_not_block(): void
    {
        $first = $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated()->json('id');

        $this->postJson('/api/v1/venue-bookings/quote', $this->booking(['name' => 'Other']))
            ->assertJsonCount(1, 'clashes')
            ->assertJsonPath('clashes.0.id', $first);
        $second = $this->postJson('/api/v1/venue-bookings', $this->booking(['name' => 'Karim', 'phone' => '01811223344']))->assertCreated()->json('id');

        // But only one of them can be confirmed.
        $this->postJson("/api/v1/venue-bookings/{$first}/status", ['status' => 'Confirmed'])->assertOk();
        $this->postJson("/api/v1/venue-bookings/{$second}/status", ['status' => 'Confirmed'])->assertUnprocessable()->assertJsonValidationErrors('hall_id');
        $this->postJson("/api/v1/venue-bookings/{$second}/status", ['status' => 'Declined', 'reason' => 'Date taken'])
            ->assertOk()->assertJsonPath('decline_reason', 'Date taken');
    }

    #[Test]
    public function payments_are_recorded_and_the_balance_shown(): void
    {
        $id = $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated()->json('id');

        $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 30000, 'method' => 'bkash', 'note' => 'Advance'])
            ->assertCreated()
            ->assertJsonPath('paid', 30000)
            ->assertJsonPath('due', 85000)
            ->assertJsonPath('payments.0.method', 'bkash');

        $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 90000, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 100, 'method' => 'crypto'])->assertUnprocessable();

        $payment = $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 85000, 'method' => 'cash'])->assertJsonPath('due', 0)->json('payments.1.id');

        // Paid money is not deleted with the booking; a mistaken payment can be removed.
        $this->deleteJson("/api/v1/venue-bookings/{$id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/venue-bookings/{$id}/payments/{$payment}")->assertOk()->assertJsonPath('due', 85000);
    }

    #[Test]
    public function an_event_can_run_past_midnight(): void
    {
        $this->postJson('/api/v1/venue-bookings', $this->booking(['start_time' => '19:00', 'end_time' => '01:00']))
            ->assertCreated()
            ->assertJsonPath('end_date', now()->addDays(11)->format('Y-m-d'))
            ->assertJsonPath('end_time', '01:00');
    }

    #[Test]
    public function editing_keeps_the_agreed_package_price(): void
    {
        $id = $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated()->json('id');
        $this->putJson("/api/v1/venue-packages/{$this->package->id}", ['price_per_person' => 1000])->assertOk();

        // More guests: food at the 850 agreed, not the new 1,000.
        $this->putJson("/api/v1/venue-bookings/{$id}", $this->booking(['guests' => 120]))
            ->assertOk()
            ->assertJsonPath('package.price_per_person', 850)
            ->assertJsonPath('food_amount', 102000)
            ->assertJsonPath('total', 132000);
    }

    #[Test]
    public function the_guest_phone_is_required_and_the_past_refused(): void
    {
        $this->postJson('/api/v1/venue-bookings', array_diff_key($this->booking(), ['phone' => 1]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/venue-bookings', $this->booking(['date' => now()->subDays(5)->format('Y-m-d')]))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/venue-bookings', $this->booking(['event_type' => 'rave']))->assertUnprocessable()->assertJsonValidationErrors('event_type');
    }

    #[Test]
    public function the_calendar_shows_a_months_bookings(): void
    {
        $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated();
        $this->postJson('/api/v1/venue-bookings', $this->booking(['date' => now()->addDays(60)->format('Y-m-d')]))->assertCreated();

        $this->getJson('/api/v1/venue-bookings?from='.now()->format('Y-m-d').'&to='.now()->addDays(30)->format('Y-m-d'))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/venue-bookings?view=pending')->assertJsonPath('counts.pending', 2);
    }

    #[Test]
    public function a_venue_with_bookings_to_come_cannot_be_deleted(): void
    {
        $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated();

        $this->deleteJson("/api/v1/venues/{$this->hall->id}")->assertUnprocessable();
        $this->putJson("/api/v1/venues/{$this->hall->id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
    }

    #[Test]
    public function only_staff_who_run_events_can_use_it(): void
    {
        $this->actAs('branch_manager');
        $this->getJson('/api/v1/venue-bookings')->assertOk();

        $this->actAs('pos_manager');
        $this->getJson('/api/v1/venue-bookings')->assertForbidden();
        $this->getJson('/api/v1/venues')->assertForbidden();
    }

    #[Test]
    public function a_website_enquiry_arrives_as_a_tentative_booking(): void
    {
        $enquiry = fn (array $o = []) => $this->postJson('/api/v1/site/spice/venue-enquiries', array_merge([
            'name' => 'Nadia', 'email' => 'nadia@example.com', 'phone' => '01711000999', 'hall_id' => $this->hall->id, 'event_type' => 'birthday',
            'date' => $this->day(20), 'start_time' => '17:00', 'end_time' => '21:00', 'guests' => 60, 'notes' => 'Kids party',
        ], $o));

        $enquiry(['phone' => null])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $enquiry(['email' => null])->assertUnprocessable()->assertJsonValidationErrors('email');
        $enquiry(['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $enquiry(['date' => now()->subDay()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('date');

        $id = $enquiry()->assertCreated()->assertJsonPath('venue', 'Grand Hall')->json('enquiry_id');

        $this->getJson("/api/v1/venue-bookings/{$id}")
            ->assertJsonPath('status', 'Pending')
            ->assertJsonPath('source', 'website')
            ->assertJsonPath('guest_phone', '+8801711000999')
            // The rent alone; food and extras are added when staff call back.
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('notes', 'Kids party');

        // A venue switched off takes no enquiries.
        $this->putJson("/api/v1/venues/{$this->hall->id}", ['is_active' => false])->assertOk();
        $enquiry()->assertUnprocessable()->assertJsonValidationErrors('hall_id');
    }

    #[Test]
    public function the_website_shows_the_venues_on_an_events_page(): void
    {
        $pages = $this->getJson('/api/v1/site-pages')->assertOk();
        $this->assertContains('events', $pages->json('pages.*.kind'));
        $events = collect($pages->json('pages'))->firstWhere('kind', 'events');
        $this->assertSame('venues', $events['sections'][0]['type']);

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice/events')
            ->assertOk()
            ->assertJsonPath('venues.0.name', 'Grand Hall')
            ->assertJsonPath('venues.0.capacity', 200);
    }

    #[Test]
    public function venue_payments_count_as_income_in_the_reports(): void
    {
        $id = $this->postJson('/api/v1/venue-bookings', $this->booking())->assertCreated()->json('id');
        $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 30000, 'method' => 'cash'])->assertCreated();

        $this->getJson('/api/v1/reports/profit')
            ->assertOk()
            ->assertJsonPath('summary.venue_income', 30000)
            ->assertJsonPath('summary.total_income', fn ($v) => $v >= 30000);

        $line = collect($this->getJson('/api/v1/reports/accounting-by-header')->assertOk()->json('income'))
            ->firstWhere('source', 'venue_bookings');
        $this->assertSame(30000, (int) $line['total']);
        $this->assertSame(1, $line['entries']);
    }

    #[Test]
    public function the_guest_is_kept_informed_by_email(): void
    {
        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'secret']);
        Http::fake(['website.test/*' => Http::response(['status' => 'queued'])]);
        $sent = fn () => collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/api/notifications/venue-booking'))
            ->values();

        // A website enquiry: "we have it".
        $id = $this->postJson('/api/v1/site/spice/venue-enquiries', [
            'name' => 'Nadia', 'email' => 'Nadia@Example.com', 'phone' => '01711000999', 'hall_id' => $this->hall->id, 'event_type' => 'birthday',
            'date' => $this->day(20), 'start_time' => '17:00', 'end_time' => '21:00', 'guests' => 60,
        ])->assertCreated()->json('enquiry_id');

        $first = $sent()->last();
        $this->assertSame('received', $first['kind']);
        $this->assertSame('nadia@example.com', $first['guest']['email']);
        $this->assertSame('Spice Garden', $first['restaurant']['name']);
        $this->assertSame('Bearer secret', $first->header('Authorization')[0]);

        // Staff send the quotation, then confirm, then take a payment.
        $this->postJson("/api/v1/venue-bookings/{$id}/email")->assertOk()->assertJsonPath('to', 'nadia@example.com');
        $this->assertSame('quotation', $sent()->last()['kind']);

        $this->postJson("/api/v1/venue-bookings/{$id}/status", ['status' => 'Confirmed'])->assertOk();
        $this->assertSame('confirmed', $sent()->last()['kind']);

        $this->postJson("/api/v1/venue-bookings/{$id}/payments", ['amount' => 5000, 'method' => 'bkash'])->assertCreated();
        $receipt = $sent()->last();
        $this->assertSame('payment', $receipt['kind']);
        $this->assertSame(5000, (int) $receipt['payment']['amount']);
        $this->assertSame(15000, (int) $receipt['due']);

        $this->getJson("/api/v1/venue-bookings/{$id}")->assertJsonPath('last_emailed_at', fn ($v) => $v !== null);
        $this->assertCount(4, $sent());

        // Confirming an already confirmed booking changes nothing and sends nothing.
        $this->postJson("/api/v1/venue-bookings/{$id}/status", ['status' => 'Confirmed'])->assertOk();
        $this->assertCount(4, $sent());
    }

    #[Test]
    public function no_email_no_message_and_staff_are_told(): void
    {
        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'secret']);
        Http::fake();

        $id = $this->postJson('/api/v1/venue-bookings', $this->booking(['status' => 'Confirmed']))->assertCreated()->json('id');

        Http::assertNothingSent();
        $this->postJson("/api/v1/venue-bookings/{$id}/email")->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson("/api/v1/venue-bookings/{$id}", $this->booking(['email' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
