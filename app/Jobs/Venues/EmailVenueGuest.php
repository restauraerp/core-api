<?php

namespace App\Jobs\Venues;

use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\WebsiteNotifier;
use App\Support\Branding\RestaurantBranding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a venue booking's guest about it, through the website - which owns
 * every customer-facing email, branded and rate-limited (see AGENTS.md).
 * core-api only decides that an email is due and hands over the facts.
 *
 * Queued, so a slow website never holds up staff confirming a booking. The
 * booking is read when the job runs: an email about a payment shows the
 * balance as it stands then.
 */
class EmailVenueGuest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** What the email is about. */
    public const KINDS = ['received', 'quotation', 'confirmed', 'declined', 'cancelled', 'payment'];

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $tenantId, public int $bookingId, public string $kind) {}

    /**
     * Queues one, when the guest gave an email. Never fails the caller: on a
     * sync queue a website error would otherwise surface in the request.
     *
     * Not called queue(): Laravel's dispatcher treats a job's queue() method
     * as its own hook for pushing the job, and would call this instead.
     */
    public static function notify(VenueBooking $booking, string $kind): void
    {
        if (! $booking->guest_email) {
            return;
        }

        try {
            self::dispatch((int) $booking->tenant_id, $booking->id, $kind);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handle(TenantContext $context, WebsiteNotifier $notifier): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        // No website to hand it to (a local or test setup): nothing to do.
        if (! config('platform.website_url') || ! config('platform.token')) {
            return;
        }

        $context->runFor($tenant, function () use ($notifier) {
            $booking = VenueBooking::with(['hall', 'location', 'payments'])->find($this->bookingId);

            if ($booking === null || ! $booking->guest_email) {
                return;
            }

            if (! $notifier->venueBooking(self::payload($booking, $this->kind))) {
                // Thrown, so the queue tries again later (backoff above).
                throw new \RuntimeException("The website did not take the {$this->kind} email for venue booking {$booking->id}.");
            }

            $booking->forceFill(['last_emailed_at' => now()])->save();
        });
    }

    /** @return array<string, mixed> everything the email says, in the restaurant's own time */
    public static function payload(VenueBooking $b, string $kind): array
    {
        $tz = config('app.timezone');
        $branding = RestaurantBranding::current();
        $paid = round((float) $b->payments->sum('amount'), 2);
        $last = $b->payments->sortByDesc('paid_at')->first();

        return [
            'kind' => $kind,
            'reference' => 'VB-'.str_pad((string) $b->id, 5, '0', STR_PAD_LEFT),
            'restaurant' => [
                'name' => $branding['name'] ?? 'Restaurant',
                'phone' => $branding['phone'] ?? null,
                'email' => $branding['email'] ?? null,
                'address' => $branding['address'] ?? null,
                'currency' => $branding['currency'] ?? '৳',
            ],
            'guest' => ['name' => $b->guest_name, 'email' => $b->guest_email],
            'event' => [
                'type' => ucfirst(str_replace('_', ' ', $b->event_type === 'holud' ? 'gaye holud' : $b->event_type)),
                'title' => $b->event_title,
                'date' => $b->starts_at->copy()->setTimezone($tz)->format('l, j F Y'),
                'time' => $b->starts_at->copy()->setTimezone($tz)->format('g:i A').' - '.$b->ends_at->copy()->setTimezone($tz)->format('g:i A'),
                'venue' => trim(($b->hall?->name ?? '').($b->location ? ', '.$b->location->name : ''), ', '),
                'guests' => $b->guests,
            ],
            'lines' => array_values(array_filter([
                ['label' => 'Venue rent', 'amount' => (float) $b->rent_amount],
                $b->package_name ? ['label' => "{$b->package_name} × {$b->guests}", 'amount' => round($b->package_price * $b->guests, 2)] : null,
                ...array_map(fn ($x) => ['label' => (string) $x['label'], 'amount' => (float) $x['amount']], $b->extras ?? []),
                $b->discount > 0 ? ['label' => 'Discount', 'amount' => -(float) $b->discount] : null,
            ])),
            'total' => (float) $b->total,
            'paid' => $paid,
            'due' => round(max(0, $b->total - $paid), 2),
            'payment' => $kind === 'payment' && $last ? ['amount' => (float) $last->amount, 'method' => $last->method] : null,
            'reason' => in_array($kind, ['declined', 'cancelled'], true) ? $b->decline_reason : null,
        ];
    }
}
