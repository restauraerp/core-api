<?php

namespace App\Jobs\Hotel;

use App\Models\RoomBooking;
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
 * Emails a room booking's guest - when they gave an email; the phone is how
 * they are reached otherwise. Through the website, which owns every
 * customer-facing email (see AGENTS.md); the twin of EmailVenueGuest.
 */
class EmailRoomGuest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const KINDS = ['received', 'confirmed', 'declined', 'cancelled'];

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $tenantId, public int $bookingId, public string $kind) {}

    /** Queues one when the guest gave an email. Never fails the caller. Not queue(): see EmailVenueGuest. */
    public static function notify(RoomBooking $booking, string $kind): void
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
        if ($tenant === null || ! config('platform.website_url') || ! config('platform.token')) {
            return;
        }

        $context->runFor($tenant, function () use ($notifier) {
            $booking = RoomBooking::with(['roomType', 'location', 'payments'])->find($this->bookingId);

            if ($booking === null || ! $booking->guest_email) {
                return;
            }

            if (! $notifier->roomBooking(self::payload($booking, $this->kind))) {
                throw new \RuntimeException("The website did not take the {$this->kind} email for room booking {$booking->id}.");
            }

            $booking->forceFill(['last_emailed_at' => now()])->save();
        });
    }

    /** @return array<string, mixed> */
    public static function payload(RoomBooking $b, string $kind): array
    {
        $branding = RestaurantBranding::current();
        $paid = round((float) $b->payments->sum('amount'), 2);
        $nights = $b->nights();

        return [
            'kind' => $kind,
            'reference' => 'RB-'.str_pad((string) $b->id, 5, '0', STR_PAD_LEFT),
            'hotel' => [
                'name' => $branding['name'] ?? 'Hotel',
                'phone' => $b->location?->phone ?: ($branding['phone'] ?? null),
                'email' => $branding['email'] ?? null,
                'address' => $b->location?->address ?: ($branding['address'] ?? null),
                'currency' => $branding['currency'] ?? '৳',
            ],
            'guest' => ['name' => $b->guest_name, 'email' => $b->guest_email],
            'stay' => [
                'room_type' => $b->roomType?->name ?? 'Room',
                'check_in' => $b->check_in->format('l, j F Y'),
                'check_out' => $b->check_out->format('l, j F Y'),
                'nights' => $nights,
                'guests' => $b->adults.' adult'.($b->adults === 1 ? '' : 's').($b->children ? ', '.$b->children.' child'.($b->children === 1 ? '' : 'ren') : ''),
            ],
            'lines' => array_values(array_filter([
                ['label' => "{$nights} night".($nights === 1 ? '' : 's').' - '.($b->roomType?->name ?? 'room'), 'amount' => (float) $b->room_amount],
                ...array_map(fn ($x) => ['label' => (string) $x['label'], 'amount' => (float) $x['amount']], $b->extras ?? []),
                $b->discount > 0 ? ['label' => 'Discount', 'amount' => -(float) $b->discount] : null,
            ])),
            'total' => (float) $b->total,
            'paid' => $paid,
            'due' => round(max(0, $b->total - $paid), 2),
            'reason' => in_array($kind, ['declined', 'cancelled'], true) ? $b->decline_reason : null,
        ];
    }
}
