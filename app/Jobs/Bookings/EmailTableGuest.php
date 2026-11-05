<?php

namespace App\Jobs\Bookings;

use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\WebsiteNotifier;
use App\Support\Branding\RestaurantBranding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a table booking's guest about it, through the website - which owns
 * every customer-facing email (see AGENTS.md). The twin of EmailVenueGuest.
 *
 * Queued, so a slow website never holds up staff approving a booking; it
 * throws when the website does not take the email, so the queue retries.
 */
class EmailTableGuest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const KINDS = ['received', 'confirmed', 'declined', 'cancelled'];

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $tenantId, public int $reservationId, public string $kind) {}

    /**
     * Queues one, when the guest gave an email. Never fails the caller.
     *
     * Not called queue(): Laravel's dispatcher treats a job's queue() method
     * as its own hook for pushing the job.
     */
    public static function notify(Reservation $reservation, string $kind): void
    {
        if (! $reservation->guest_email) {
            return;
        }

        try {
            self::dispatch((int) $reservation->tenant_id, $reservation->id, $kind);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handle(TenantContext $context, WebsiteNotifier $notifier): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant === null || ! config('platform.website_url') || ! config('platform.token')) {
            return;
        }

        $context->runFor($tenant, function () use ($notifier) {
            $reservation = Reservation::with(['location', 'table'])->find($this->reservationId);

            if ($reservation === null || ! $reservation->guest_email) {
                return;
            }

            if (! $notifier->tableBooking(self::payload($reservation, $this->kind))) {
                throw new \RuntimeException("The website did not take the {$this->kind} email for table booking {$reservation->id}.");
            }

            $reservation->forceFill(['last_emailed_at' => now()])->save();
        });
    }

    /** @return array<string, mixed> */
    public static function payload(Reservation $r, string $kind): array
    {
        $tz = config('app.timezone');
        $branding = RestaurantBranding::current();
        $at = $r->reservation_date?->copy()->setTimezone($tz);

        return [
            'kind' => $kind,
            'reference' => 'TB-'.str_pad((string) $r->id, 5, '0', STR_PAD_LEFT),
            'restaurant' => [
                'name' => $branding['name'] ?? 'Restaurant',
                'phone' => $r->location?->phone ?: ($branding['phone'] ?? null),
                'email' => $branding['email'] ?? null,
                'address' => $r->location?->address ?: ($branding['address'] ?? null),
            ],
            'guest' => ['name' => $r->guest_name ?: 'Guest', 'email' => $r->guest_email],
            'booking' => [
                'date' => $at?->format('l, j F Y') ?? '',
                'time' => $at?->format('g:i A') ?? '',
                'guests' => (int) $r->guest_count,
                'branch' => $r->location?->name,
                'table' => $kind === 'confirmed' ? $r->table?->name : null,
                'notes' => $r->notes,
            ],
            'reason' => in_array($kind, ['declined', 'cancelled'], true) ? $r->decline_reason : null,
        ];
    }
}
