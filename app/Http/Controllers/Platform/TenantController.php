<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\OneTimeLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\PasswordResetLink;
use App\Support\Billing\Plans;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tenant and subscription management for the marketing website.
 *
 * This is the console commands' work - tenants:create and tenants:subscribe -
 * exposed over HTTP so a visitor can start a trial or pay for a plan without
 * someone at a terminal. It is guarded by the platform token, never by a user
 * token: no customer should be able to call any of this.
 */
class TenantController extends Controller
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private TenantContext $context,
    ) {}

    /**
     * The tenants, for the marketing site's admin panel.
     *
     * The website's tenant screens are all backed by this one listing: the
     * status tab filters it, and the per-tab counts drive the badges on the
     * tabs themselves. `status` values mirror the website's TenantController
     * TABS, with 'trashed' meaning soft-deleted.
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $statuses = ['all', 'trialing', 'active', 'suspended', 'cancelled', 'trashed'];

        if (! in_array($status, $statuses, true)) {
            $status = 'all';
        }

        // The whole query is built AND run without tenant scoping.
        //
        // withCount() compiles the related model's global scopes into a
        // subquery when the query is built, not when it runs - so unscoping
        // only the paginate() call leaves TenantScope's `1 = 0` already baked
        // in and every count comes back zero. This endpoint is legitimately
        // cross-tenant; the helper restores the previous scoping state after.
        $paginator = $this->context->runWithoutScoping(function () use ($status, $search, $perPage) {
            $query = Tenant::query()->withCount(['users', 'locations']);

            if ($status === 'trashed') {
                $query->onlyTrashed();
            } elseif ($status !== 'all') {
                $query->withoutTrashed()->where('status', $status);
            } else {
                $query->withoutTrashed();
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%");
                });
            }

            return $query->orderByDesc('id')->paginate($perPage);
        });

        $counts = array_combine($statuses, array_map(
            fn ($tab) => match ($tab) {
                'trashed' => Tenant::onlyTrashed()->count(),
                'all' => Tenant::withoutTrashed()->count(),
                default => Tenant::withoutTrashed()->where('status', $tab)->count(),
            },
            $statuses
        ));

        return response()->json([
            'tenants' => collect($paginator->items())
                ->map(fn (Tenant $tenant) => $this->present($tenant))
                ->values(),
            'counts' => $counts,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
            ],
        ]);
    }

    /**
     * Creates a restaurant on trial and hands back everything its owner needs
     * to get in.
     *
     * Idempotent on contact_email: the website retries this when a visitor
     * refreshes the "creating your account" screen, and a retry must return the
     * account already made rather than a second one. A retry does mint a fresh
     * login link, because the earlier one may never have arrived.
     */
    public function store(Request $request): JsonResponse
    {
        // Checked before anything is written. The login link is built at the
        // very end, so a missing FRONTEND_URL would otherwise commit a tenant
        // and then fail the response - leaving an orphaned restaurant behind on
        // every attempt, and a retry hitting the same wall.
        $this->requireAppUrl();

        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'plan' => ['nullable', 'string', Rule::in(Plans::tiers())],
        ]);

        // withTrashed on purpose. The address is verified before this is ever
        // called, so whoever is asking owns it - and one person has one
        // restaurant. Without this, somebody whose restaurant is in the trash
        // gets a brand new second one against the same address, and from then
        // on "do they already have an account" has two answers.
        $existing = Tenant::withTrashed()
            ->where('contact_email', $validated['email'])
            ->first();

        if ($existing !== null) {
            $reactivated = $this->reinstate($existing);

            $owner = User::withoutGlobalScopes()
                ->where('tenant_id', $existing->getKey())
                ->where('email', $validated['email'])
                ->first();

            return response()->json([
                'created' => false,
                // Whether anything had to be brought back, so the caller can
                // explain rather than silently showing a different restaurant
                // from the one they just asked for.
                'reactivated' => $reactivated,
                'tenant' => $this->present($existing->fresh()),
                'login' => $owner === null ? null : $this->loginPayload($existing, $owner),
            ]);
        }

        [$tenant, $owner] = DB::transaction(function () use ($validated) {
            $tenant = $this->provisioner->create([
                'name' => $validated['restaurant_name'],
                'plan' => $validated['plan'] ?? Plans::default(),
                'status' => 'trialing',
                'contact_name' => $validated['owner_name'],
                'contact_email' => $validated['email'],
                'contact_phone' => $validated['phone'] ?? null,
                // Same trial for everyone, from config/billing.php. A trial has
                // no grace period: the day it ends the tenant goes read-only.
                'trial_ends_at' => Carbon::now()->addDays((int) config('billing.trial_days', 7)),
            ], [
                'name' => $validated['owner_name'],
                'email' => $validated['email'],
                // Nobody is ever told this. The owner arrives through a
                // one-time link and chooses their own password on the way in.
                'password' => Str::password(32),
                'phone' => $validated['phone'] ?? null,
            ]);

            $owner = User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('email', $validated['email'])
                ->firstOrFail();

            $owner->forceFill(['must_set_password' => true])->save();

            return [$tenant, $owner];
        });

        return response()->json([
            'created' => true,
            'tenant' => $this->present($tenant),
            'login' => $this->loginPayload($tenant, $owner),
        ], 201);
    }

    /**
     * Brings a returning owner's restaurant back, without handing anything out.
     *
     * A working account is left exactly as it is: an active subscription, or a
     * trial still running, is not something a repeat signup should disturb.
     * Anything else - in the trash, cancelled, already suspended - comes back
     * suspended. They can get in and read their data; saving needs a payment
     * and a person.
     *
     * That is the deliberate part. Reactivating outright would mean a customer
     * whose payment was rejected could clear the lock by filling the signup
     * form in again, which would leave no enforcement behind manual settlement
     * at all.
     *
     * @return bool whether anything actually changed
     */
    private function reinstate(Tenant $tenant): bool
    {
        $wasTrashed = $tenant->trashed();

        // A live account is left completely alone. Note this is checked before
        // restoring: trashing is a soft delete and does not touch `status`, so
        // a restaurant in the trash usually still reads "active" - and treating
        // that as a working account would quietly undo the removal.
        if (! $wasTrashed && in_array($tenant->status, ['active', 'trialing'], true)) {
            return false;
        }

        if ($wasTrashed) {
            $tenant->restore();
        }

        $tenant->status = 'suspended';
        $tenant->save();

        Subscription::forget($tenant);

        return true;
    }

    /**
     * Reads a tenant back, by slug.
     *
     * Includes trashed tenants: the marketing site's admin panel has to open
     * a restaurant that is in the trash to restore it or delete it for ever.
     *
     * Counted inside runWithoutScoping for the reason index() documents.
     */
    public function show(string $slug): JsonResponse
    {
        $tenant = $this->context->runWithoutScoping(fn () => Tenant::withTrashed()
            ->withCount(['users', 'locations'])
            ->where('slug', $slug)
            ->firstOrFail());

        return response()->json(['tenant' => $this->present($tenant)]);
    }

    /**
     * Turns a subscription on, or extends one already running.
     *
     * Mirrors tenants:subscribe: an unexpired subscription is extended from
     * where it ends, not from today, so paying early never costs the customer
     * the days they already had.
     *
     * `starts_at` overrides that and runs the period from a date given by hand.
     * It is what an admin recording an offline payment needs: money that
     * arrived three weeks ago bought a month from three weeks ago, not a month
     * from today. Because it can be back-dated far enough that the period has
     * already elapsed, the resulting status is derived from the end date rather
     * than assumed - see below.
     */
    public function subscribe(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'plan' => ['nullable', 'string', Rule::in(Plans::tiers())],
            'starts_at' => ['nullable', 'date'],
        ]);

        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        // An explicit start replaces the running period rather than extending
        // it: the admin is stating when this one began, and silently adding it
        // to an existing window would hand out time nobody paid for.
        $from = isset($validated['starts_at'])
            ? Carbon::parse($validated['starts_at'])->startOfDay()
            : ($tenant->subscription_ends_at?->isFuture()
                ? $tenant->subscription_ends_at->copy()
                : Carbon::now());

        $endsAt = $validated['cycle'] === 'yearly'
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();

        if (isset($validated['plan']) && $validated['plan'] !== $tenant->plan) {
            $tenant->plan = $validated['plan'];
            $tenant->max_outlets = Plans::outletLimit($validated['plan']);
            // Role permissions are capped by the tier, so a plan change has to
            // resync them or the customer pays for modules they cannot see.
            $this->provisioner->createRoles($tenant);
        }

        // A back-dated period that has already run out must not switch the
        // account on: the honest outcome is a recorded subscription that has
        // lapsed. Reporting it active would give away time on the strength of a
        // historical payment.
        $lapsed = $endsAt->isPast();

        if (! $lapsed) {
            $tenant->status = 'active';
        } elseif ($tenant->status === 'active') {
            // Its access rested on a window we have just replaced with one that
            // has already ended. tenants:expire would suspend it tonight; doing
            // it here stops the column claiming a subscription that is over.
            // A tenant still inside a trial is left alone - the trial is real
            // and is not what this payment replaced.
            $tenant->status = 'suspended';
        }

        $tenant->billing_cycle = $validated['cycle'];
        $tenant->subscription_ends_at = $endsAt;
        $tenant->save();

        Subscription::forget($tenant);

        return response()->json([
            'tenant' => $this->present($tenant->fresh()),
            // So the caller can say "recorded, but it had already expired"
            // rather than claiming the restaurant is now subscribed.
            'starts_at' => $from->toIso8601String(),
            'lapsed' => $lapsed,
        ]);
    }

    /**
     * Suspends a tenant - the lever behind "mark the order unverified and lock
     * the account" when a claimed payment turns out not to have arrived.
     */
    public function suspend(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        $tenant->status = 'suspended';
        $tenant->save();

        Subscription::forget($tenant);

        return response()->json(['tenant' => $this->present($tenant->fresh())]);
    }

    /**
     * Lifts a suspension without touching the billing window.
     *
     * Deliberately not `subscribe`: that extends the paid period, so using it
     * to undo a mistaken lock would hand the customer a free month every time
     * someone clicked the wrong button.
     */
    public function reactivate(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        // What to go back to depends on what they had. A tenant whose paid
        // period is still running is active; one still inside a trial goes back
        // to trialing; anything else has genuinely lapsed and needs paying for,
        // so refuse rather than quietly grant access.
        $status = match (true) {
            $tenant->subscription_ends_at?->isFuture() => 'active',
            $tenant->trial_ends_at?->isFuture() => 'trialing',
            default => null,
        };

        if ($status === null) {
            return response()->json([
                'message' => 'This tenant has nothing left to reactivate - subscribe it instead.',
                'error' => 'nothing_to_reactivate',
            ], 422);
        }

        $tenant->status = $status;
        $tenant->save();

        Subscription::forget($tenant);

        return response()->json(['tenant' => $this->present($tenant->fresh())]);
    }

    /**
     * Mints a fresh single-use login link for a tenant's owner.
     */
    public function loginLink(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        $tenantUsers = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey());

        $isAdmin = fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'restaurant_admin'));

        $owner = $isAdmin(
            (clone $tenantUsers)->where('email', $tenant->contact_email)
        )->first();

        if ($owner === null) {
            $owner = (clone $tenantUsers)
                ->where('email', $tenant->contact_email)
                ->oldest()
                ->first();
        }

        if ($owner === null) {
            $owner = $isAdmin(clone $tenantUsers)->oldest()->first();
        }

        if ($owner === null) {
            $owner = (clone $tenantUsers)->oldest()->firstOrFail();
        }

        return response()->json(['login' => $this->loginPayload($tenant, $owner)]);
    }

    /**
     * Emails the owner a link for setting a new password.
     *
     * The admin counterpart of "I have forgotten my password": support can send
     * one on request without ever handling the password itself, and without
     * reading a login link down a phone line.
     *
     * Unlike the public route this one answers honestly - the caller is our own
     * admin panel, holding the platform token, so there is nothing to hide from
     * it and plenty it needs to report back.
     */
    public function sendPasswordReset(string $slug, PasswordResetLink $links): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        $owner = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('email', $tenant->contact_email)
            ->first();

        if ($owner === null) {
            return response()->json([
                'message' => 'No user matches this restaurant\'s contact email, so there is nobody to send a reset to.',
                'error' => 'owner_missing',
            ], 422);
        }

        if (! $links->send($owner, $tenant)) {
            return response()->json([
                'message' => 'The demo account cannot be reset - its credentials are published.',
                'error' => 'account_not_resettable',
            ], 422);
        }

        return response()->json([
            'sent' => true,
            'email' => $owner->email,
        ]);
    }

    /**
     * Moves a tenant to the trash. Soft delete - nothing is destroyed, so this
     * is always reversible and never needs a second confirmation step.
     */
    public function trash(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        $tenant->delete();

        return response()->json(['tenant' => $this->present($tenant)]);
    }

    /**
     * Restores a tenant from the trash, exactly as it was.
     */
    public function restore(string $slug): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()->where('slug', $slug)->firstOrFail();

        $tenant->restore();

        return response()->json(['tenant' => $this->present($tenant->fresh())]);
    }

    /**
     * Destroys a tenant and everything it owns.
     *
     * Delegates to tenants:remove rather than force-deleting here. The FK
     * cascade alone leaves behind everything no foreign key reaches - the
     * users' sessions and API tokens, the model_has_* pivots, the uploaded
     * files, the cached billing state, and the website's own records of the
     * signup - and that command sweeps all of it. It also discovers
     * tenant-owned tables from the schema, so a table added later is covered
     * without anyone remembering to update this.
     *
     * Refuses anything not already in the trash: wiping a live restaurant must
     * always be the second deliberate step after moving it there, never the
     * first. A 422 rather than a 404, so the website can say why.
     */
    public function purge(string $slug): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()->where('slug', $slug)->first();

        if ($tenant === null) {
            return response()->json([
                'message' => 'Only a restaurant already in the trash can be permanently deleted.',
                'error' => 'not_trashed',
            ], 422);
        }

        Artisan::call('tenants:remove', ['tenant' => $slug, '--force' => true]);

        return response()->json([
            'deleted' => true,
            'restaurant_code' => $slug,
            // The command's own report - which tables lost how many rows, how
            // many files went - kept so a purge can be accounted for after.
            'output' => trim(Artisan::output()),
        ]);
    }

    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'restaurant_code' => $tenant->slug,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'billing_cycle' => $tenant->billing_cycle,
            'contact_name' => $tenant->contact_name,
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'max_outlets' => $tenant->max_outlets,
            // Only present when the caller asked for them - index() and show()
            // do. Deliberately not counted here as a fallback: users and
            // locations are tenant-scoped, so a count taken outside a tenant
            // context returns a confident, wrong zero rather than nothing.
            'locations_count' => $tenant->locations_count ?? null,
            'users_count' => $tenant->users_count ?? null,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
            'deleted_at' => $tenant->deleted_at?->toIso8601String(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /**
     * Refuses to go on without somewhere to send the customer.
     *
     * A login URL is only ever read by the customer, so a wrong one is
     * invisible to us until somebody says they cannot get in.
     */
    private function requireAppUrl(): string
    {
        $appUrl = rtrim((string) config('platform.app_url'), '/');

        if ($appUrl === '') {
            throw new RuntimeException(
                'FRONTEND_URL is not set, so no login link can be built for this tenant.'
            );
        }

        return $appUrl;
    }

    private function loginPayload(Tenant $tenant, User $owner): array
    {
        $appUrl = $this->requireAppUrl();
        $token = OneTimeLoginToken::issueFor($owner);

        return [
            'login_url' => $appUrl.'/login',
            'restaurant_code' => $tenant->slug,
            'email' => $owner->email,
            'one_time_token' => $token,
            // Built here rather than by each caller so the front's route for
            // redeeming a link is defined in one place.
            'one_time_login_url' => $appUrl.'/login/one-time?token='.urlencode($token),
            'expires_in_hours' => min(
                (int) config('platform.login_link_ttl_hours', 24),
                OneTimeLoginToken::MAX_TTL_HOURS,
            ),
        ];
    }
}
