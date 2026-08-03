<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * The current tenant, for the lifetime of one request (or one console command).
 *
 * Registered as a singleton. Everything that needs to know "whose data is
 * this?" reads it from here rather than from the request, so queued jobs,
 * console commands and seeders can set it explicitly.
 *
 * Setting the tenant also sets Spatie's permissions team id - they must never
 * drift apart, or a user's roles silently resolve against the wrong tenant.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * When true the tenant global scope is skipped entirely. Reserved for
     * platform admins doing cross-tenant work and for install/demo seeding.
     */
    private bool $bypassed = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // Keep Spatie in lockstep. NULL is meaningful to Spatie too: it matches
        // only global (tenant_id IS NULL) role definitions.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey());
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->set(null);
    }

    public function bypass(bool $bypassed = true): void
    {
        $this->bypassed = $bypassed;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Run a callback as a given tenant, restoring the previous context after -
     * including when the callback throws. This is the only safe way to touch
     * more than one tenant in a single process (seeders, platform reports).
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypassed;

        $this->set($tenant);
        $this->bypass(false);

        try {
            return $callback($tenant);
        } finally {
            $this->set($previousTenant);
            $this->bypass($previousBypass);
        }
    }

    /**
     * Run a callback with tenant scoping switched off. Use sparingly and never
     * on a path reachable by a non-platform-admin request.
     */
    public function runWithoutScoping(Closure $callback): mixed
    {
        $previousBypass = $this->bypassed;

        $this->bypass(true);

        try {
            return $callback();
        } finally {
            $this->bypass($previousBypass);
        }
    }
}
