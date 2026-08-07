<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sets a new password for a user inside a tenant.
 *
 * The support path for "the owner is locked out": there is no self-service
 * forgotten-password flow, and the API only lets an authenticated user of the
 * tenant change a password - which is exactly what a locked-out owner cannot
 * do.
 *
 * Resetting also kills the existing logins by default. A password reset is
 * usually a response to a lost or leaked credential, so leaving the old
 * sessions and API tokens alive would defeat the point.
 */
class ResetTenantPassword extends Command
{
    protected $signature = 'tenants:reset-password
        {tenant : Tenant ID or slug}
        {--email= : The user to reset - defaults to the tenant\'s owner}
        {--password= : The new password (generated and printed if omitted)}
        {--keep-sessions : Leave existing logins and API tokens working}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Set a new password for a tenant user, locking out the old sessions [--email= --password= --keep-sessions --force]';

    protected $help = <<<'HELP'
        Without --email the tenant's owner is reset - the single user holding the
        restaurant_admin role. If the tenant has several admins there is no obvious
        owner, so the command lists them and asks for --email.

        Without --password one is generated and printed once. Copy it before the
        output scrolls away; it cannot be shown again.

        Every session and API token belonging to that user is revoked, so anyone
        holding the old password is logged out immediately. Pass --keep-sessions to
        reset the password without disturbing anyone currently signed in.

        Examples:
          <info>php artisan tenants:reset-password acme-bistro</info>
            Owner reset with a generated password.

          <info>php artisan tenants:reset-password acme-bistro --password='s3cret!'</info>
            Owner reset to a chosen password.

          <info>php artisan tenants:reset-password 7 --email=manager@acme.test --force</info>
            A specific user, no prompt (use in scripts).

          <info>php artisan tenants:reset-password 7 --email=manager@acme.test --keep-sessions</info>
            New password, existing logins left alone.
        HELP;

    /** Matches the minimum the API enforces on every password field. */
    private const MIN_LENGTH = 8;

    public function handle(TenantContext $context): int
    {
        $identifier = (string) $this->argument('tenant');

        $tenant = Tenant::withTrashed()
            ->when(
                ctype_digit($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();

        if ($tenant === null) {
            $this->error("No tenant matches [{$identifier}].");

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password !== null && Str::length($password) < self::MIN_LENGTH) {
            $this->error('The password must be at least '.self::MIN_LENGTH.' characters - the API would reject anything shorter.');

            return self::FAILURE;
        }

        // Users are tenant-scoped and roles are resolved against Spatie's team
        // id, so all of it has to run as this tenant - outside the context the
        // lookups come back empty.
        return $context->runFor($tenant, fn () => $this->reset($tenant, $password));
    }

    private function reset(Tenant $tenant, ?string $password): int
    {
        $user = $this->resolveUser($tenant);

        if ($user === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line("Tenant #{$tenant->id} \"{$tenant->name}\" (code: {$tenant->slug}, status: {$tenant->status})");
        $this->line("  User: {$user->name} <{$user->email}> (#{$user->id})");
        $this->line('  Roles: '.($user->getRoleNames()->implode(', ') ?: 'none'));

        $sessions = $this->option('keep-sessions') ? 0 : $this->activeCredentials($user);

        if (! $this->option('keep-sessions')) {
            $this->line("  Sessions and API tokens to revoke: {$sessions}");
        }

        if (! $this->option('force') && ! $this->confirm("Reset the password for [{$user->email}]?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $generated = $password === null;
        $password ??= Str::password(16);

        DB::transaction(function () use ($user, $password) {
            // The `password` cast hashes on assignment.
            $user->password = $password;
            $user->save();

            if (! $this->option('keep-sessions')) {
                $this->revokeCredentials($user);
            }
        });

        $this->info("Password reset for {$user->email}.");

        if ($generated) {
            $this->warn("  New password: {$password}");
            $this->warn('  This is shown once. Store it now.');
        }

        $this->line($this->option('keep-sessions')
            ? '  Existing logins left alone (--keep-sessions).'
            : "  {$sessions} session(s) and token(s) revoked - everyone holding the old password is signed out.");

        return self::SUCCESS;
    }

    /**
     * The user to reset: the one named by --email, or the tenant's sole
     * restaurant_admin when no email is given.
     */
    private function resolveUser(Tenant $tenant): ?User
    {
        if ($email = $this->option('email')) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error("No user with email [{$email}] belongs to tenant [{$tenant->slug}].");
            }

            return $user;
        }

        $admins = User::role(RoleDefinitions::RESTAURANT_ADMIN)->orderBy('id')->get();

        if ($admins->isEmpty()) {
            $this->error("Tenant [{$tenant->slug}] has no ".RoleDefinitions::RESTAURANT_ADMIN.' user. Name the user to reset with --email.');

            return null;
        }

        if ($admins->count() > 1) {
            $this->error("Tenant [{$tenant->slug}] has ".$admins->count().' admins, so there is no single owner to reset. Pick one with --email:');

            foreach ($admins as $admin) {
                $this->line("  --email={$admin->email}  ({$admin->name})");
            }

            return null;
        }

        return $admins->first();
    }

    private function activeCredentials(User $user): int
    {
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->getKey())
            ->count();

        // Only present on the database session driver.
        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')->where('user_id', $user->getKey())->count()
            : 0;

        return $tokens + $sessions;
    }

    private function revokeCredentials(User $user): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->getKey())
            ->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        }
    }
}
