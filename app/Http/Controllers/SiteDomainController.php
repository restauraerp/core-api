<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\Billing\Modules;
use App\Support\Site\DnsLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Where a restaurant's website is reached.
 *
 * Every Website-module restaurant is at {code}.{site.base_domain}. It can add
 * its own domain too: it sets the domain here, points DNS at us, and verifies.
 * Only a verified domain is served - by the front, which asks resolve() which
 * restaurant a host belongs to - or given a certificate, by Caddy's on-demand
 * TLS, which asks allowed() before issuing one.
 */
class SiteDomainController extends Controller
{
    public function __construct(private TenantContext $context, private DnsLookup $dns) {}

    /** GET /site-builder/domain */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->present($this->context->get()));
    }

    /** PUT /site-builder/domain - set or clear the restaurant's own domain. */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $tenant = $this->context->get();

        $request->validate(['domain' => ['present', 'nullable', 'string', 'max:253']]);
        $domain = self::normalise($request->input('domain'));

        if ($domain !== null) {
            $base = strtolower((string) config('site.base_domain'));

            if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                throw ValidationException::withMessages(['domain' => ['Enter a domain like banglabistro.com.']]);
            }

            if ($domain === $base || str_ends_with($domain, '.'.$base)) {
                throw ValidationException::withMessages(['domain' => ['That is one of our own addresses - your site already has one.']]);
            }

            if (Tenant::withTrashed()->where('domain', $domain)->whereKeyNot($tenant->getKey())->exists()) {
                throw ValidationException::withMessages(['domain' => ['That domain is already connected to another restaurant.']]);
            }
        }

        if ($domain !== $tenant->domain) {
            $tenant->forceFill(['domain' => $domain, 'domain_verified_at' => null])->save();
        }

        return response()->json($this->present($tenant->fresh()));
    }

    /** POST /site-builder/domain/verify - check the DNS now points here. */
    public function verify(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $tenant = $this->context->get();

        if (! $tenant->domain) {
            throw ValidationException::withMessages(['domain' => ['Add your domain first.']]);
        }

        $target = strtolower((string) config('site.cname_target'));
        $ip = (string) config('site.server_ip');
        $cnames = $this->dns->cnames($tenant->domain);
        $addresses = $this->dns->addresses($tenant->domain);

        $pointsHere = in_array($target, $cnames, true) || ($ip !== '' && in_array($ip, $addresses, true));

        if (! $pointsHere) {
            $found = $cnames ? 'CNAME '.implode(', ', $cnames) : ($addresses ? 'A '.implode(', ', $addresses) : 'no record yet');
            throw ValidationException::withMessages([
                'domain' => ["{$tenant->domain} does not point to us yet (found: {$found}). DNS changes can take up to a few hours."],
            ]);
        }

        $tenant->forceFill(['domain_verified_at' => now()])->save();

        return response()->json($this->present($tenant->fresh()));
    }

    /**
     * GET /site-domains/resolve?host= - which restaurant a custom domain
     * belongs to. Asked by the front for hosts outside our own domain.
     */
    public function resolve(Request $request): JsonResponse
    {
        $tenant = $this->tenantForCustomDomain(self::normalise((string) $request->query('host')));

        return $tenant
            ? response()->json(['code' => $tenant->slug])
            : response()->json(['code' => null], 404);
    }

    /**
     * GET /site-domains/allowed?domain= - Caddy's on-demand TLS check. 200
     * means "you may get a certificate for this name"; anything else, no.
     * Without it anyone could point a domain at us and have us request
     * certificates for it until the issuer's rate limits locked us out.
     */
    public function allowed(Request $request): Response
    {
        $host = self::normalise((string) $request->query('domain'));
        $base = strtolower((string) config('site.base_domain'));

        if ($host !== null && str_ends_with($host, '.'.$base)) {
            $code = substr($host, 0, -strlen('.'.$base));
            $tenant = str_contains($code, '.') ? null : Tenant::where('slug', $code)->first();
            $ok = $tenant !== null && $tenant->hasModule(Modules::WEBSITE);
        } else {
            $ok = $this->tenantForCustomDomain($host) !== null;
        }

        return response($ok ? 'ok' : 'no', $ok ? 200 : 404);
    }

    private function tenantForCustomDomain(?string $host): ?Tenant
    {
        if ($host === null) {
            return null;
        }

        $tenant = Tenant::where('domain', $host)->whereNotNull('domain_verified_at')->first();

        return $tenant !== null && $tenant->hasModule(Modules::WEBSITE) ? $tenant : null;
    }

    /** Lower case, no scheme, path, port or trailing dot. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $host = strtolower(trim($value));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = preg_replace('#[/?\#].*$#', '', $host);
        $host = preg_replace('#:\d+$#', '', $host);

        return rtrim($host, '.') ?: null;
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }

    /** @return array<string, mixed> */
    private function present(Tenant $tenant): array
    {
        $base = (string) config('site.base_domain');

        return [
            'subdomain' => $tenant->slug.'.'.$base,
            'domain' => $tenant->domain,
            'verified' => $tenant->domain !== null && $tenant->domain_verified_at !== null,
            'verified_at' => $tenant->domain_verified_at?->toIso8601String(),
            'cname_target' => (string) config('site.cname_target'),
            'server_ip' => config('site.server_ip') ?: null,
        ];
    }
}
