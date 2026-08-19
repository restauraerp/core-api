<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Demo\SeededIdSpace;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one way demo data is (re)imported - by hand, by the deploy, and by the
 * nightly cron.
 *
 * It replaces `migrate:fresh --seed`, which dropped every table in the
 * database. That was survivable when the box hosted nothing but the demo; on a
 * SaaS install it destroys paying customers' restaurants, which is exactly what
 * happened to a tenant created with `tenants:create`.
 *
 * So the blast radius here is one tenant. The demo tenant is force-deleted
 * through `tenants:remove` - rows, sessions, tokens and uploaded files - and
 * then rebuilt by DemoSeeder. Nothing else in the database is touched, and no
 * table is ever dropped.
 *
 * Deleting first rather than re-seeding over the top is not incidental:
 * OrderSeeder derives its ids from MAX(id)+1 and bulk-inserts two years of
 * orders, so seeding a tenant that already has data doubles it. A clean tenant
 * is what `migrate:fresh` was really providing.
 */
class RefreshDemoData extends Command implements Isolatable
{
    protected $signature = 'demo:refresh
        {--force : Skip the confirmation prompt. Required from cron and CI, which have no TTY}
        {--if-demo : On a non-demo box, report and exit 0 instead of failing}
        {--dry-run : Report what would be rebuilt without changing anything}
        {--keep-assets : Leave the demo tenant\'s uploaded files on disk}
        {--skip-baseline : Skip the migrate + baseline seed step}';

    protected $description = 'Rebuild the demo tenant without touching any other tenant [--force --if-demo --dry-run]';

    protected $help = <<<'HELP'
        Destroys and rebuilds the demo restaurant. It never drops a table and never
        touches a tenant other than the demo one, so it is safe to run on a box that
        also hosts real customers.

        In order: migrate (never migrate:fresh), the idempotent baseline seed, remove
        the demo tenant via <info>tenants:remove</info> (rows, sessions, tokens and
        uploaded files), then reseed it with DemoSeeder.

        Only runs where DEMO_MODE=true. That is a property of the box rather than of
        the command line, so no copy-pasted invocation can carry the permission with
        it to a customer install. Pass --if-demo to make a non-demo box a silent
        success - that is what lets one deploy pipeline and one cron line be safe
        everywhere.

        The demo tenant gets a new ID on every refresh; its slug is the stable
        identifier clients send as X-Tenant-ID. Demo logins and API tokens are
        invalidated each time.

        Examples:
          <info>php artisan demo:refresh --dry-run</info>            report only, change nothing
          <info>php artisan demo:refresh</info>                      prompt, then rebuild
          <info>php artisan demo:refresh --force</info>              no prompt (cron, CI)
          <info>php artisan demo:refresh --force --if-demo</info>    no-op unless this is the demo box
        HELP;

    public function handle(): int
    {
        if (! config('app.demo_mode')) {
            if ($this->option('if-demo')) {
                $this->info('DEMO_MODE is off - this is not a demo deployment. Nothing to refresh.');

                return self::SUCCESS;
            }

            $this->error('Refused: demo:refresh only runs on a demo deployment.');
            $this->line("  config('app.demo_mode') is false. Set DEMO_MODE=true in .env (and run");
            $this->line('  config:clear) if this really is the demo box, or pass --if-demo to make');
            $this->line('  this a no-op wherever it is not.');

            return self::FAILURE;
        }

        $demoSlug = (string) config('app.demo_tenant_slug');
        $installSlug = (string) config('app.install_tenant_slug');

        // A typo in DEMO_TENANT_SLUG must not be able to point the deletion at
        // the install tenant, which owns the platform admin.
        if ($demoSlug === '') {
            $this->error('Refused: DEMO_TENANT_SLUG is empty, so there is no demo tenant to rebuild.');

            return self::FAILURE;
        }

        if ($demoSlug === $installSlug) {
            $this->error("Refused: DEMO_TENANT_SLUG and INSTALL_TENANT_SLUG are both [{$demoSlug}].");
            $this->line('  The install tenant is not demo data and must not be rebuilt.');

            return self::FAILURE;
        }

        $doomedSlugs = array_values(array_diff(
            array_merge([$demoSlug], (array) config('app.demo_legacy_tenant_slugs', [])),
            [$installSlug],
        ));

        // Baseline first, before anything reads the schema: on a brand new
        // database there is no `tenants` table yet, so surveying or removing
        // ahead of `migrate` dies on a missing table. Skipped under --dry-run,
        // which has to leave the database alone even though every step here is
        // idempotent.
        if (! $this->option('skip-baseline') && ! $this->option('dry-run')
            && ($code = $this->baseline()) !== self::SUCCESS) {
            return $code;
        }

        if (! $this->hasSchema()) {
            $this->error('Refused: this database has no tenants table yet.');
            $this->line('  Run without --dry-run/--skip-baseline so migrations can be applied first.');

            return self::FAILURE;
        }

        $this->survey($doomedSlugs);

        if (! $this->option('force') && ! $this->option('dry-run')
            && ! $this->confirm("Destroy and rebuild the demo tenant [{$demoSlug}]?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        if (($code = $this->removeDemoTenants($doomedSlugs)) !== self::SUCCESS) {
            return $code;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run - nothing was changed.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Seeding demo data...');

        if ($this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]) !== self::SUCCESS) {
            $this->error('DemoSeeder failed. The demo tenant may be incomplete.');

            return self::FAILURE;
        }

        $this->reclaimIdSpace();

        $this->line('');
        $this->info('Demo refreshed. Current tenants:');
        $this->call('tenants:list');

        return self::SUCCESS;
    }

    /**
     * Hands back the id space the seed reserved and did not use. The reasoning,
     * and the numbers behind it, are in SeededIdSpace.
     */
    private function reclaimIdSpace(): void
    {
        SeededIdSpace::reclaim();

        $this->line('  Reclaimed the id headroom the seed reserved.');
    }

    /**
     * Whether the database has been migrated at all. `--dry-run` and
     * `--skip-baseline` both bypass the migrate step, so an empty database can
     * still reach the survey.
     */
    private function hasSchema(): bool
    {
        return Schema::hasTable('tenants');
    }

    /**
     * Shows what dies and what does not. On a SaaS box the survivor list is the
     * more important half - it is what an operator checks before saying yes.
     *
     * @param  list<string>  $doomedSlugs
     */
    private function survey(array $doomedSlugs): void
    {
        $doomed = Tenant::withTrashed()->whereIn('slug', $doomedSlugs)->get();
        $survivors = Tenant::withTrashed()->whereNotIn('slug', $doomedSlugs)->get();

        $this->line('');

        if ($doomed->isEmpty()) {
            $this->line('No demo tenant present yet - it will be created.');
        } else {
            $this->table(
                ['Rebuilt from scratch', 'Code', 'Users', 'Orders'],
                $doomed->map(fn (Tenant $tenant) => [
                    "#{$tenant->id} {$tenant->name}",
                    $tenant->slug,
                    DB::table('users')->where('tenant_id', $tenant->id)->count(),
                    DB::table('orders')->where('tenant_id', $tenant->id)->count(),
                ])->all(),
            );
        }

        if ($survivors->isNotEmpty()) {
            $this->table(
                ['Left untouched', 'Code', 'Users', 'Orders'],
                $survivors->map(fn (Tenant $tenant) => [
                    "#{$tenant->id} {$tenant->name}",
                    $tenant->slug,
                    DB::table('users')->where('tenant_id', $tenant->id)->count(),
                    DB::table('orders')->where('tenant_id', $tenant->id)->count(),
                ])->all(),
            );
        }
    }

    /**
     * Schema and the install baseline, so a completely empty database works.
     *
     * Runs before the removal on purpose: tenants:remove reads the schema and
     * would die on a database with no tables. Every step here is idempotent -
     * `migrate` applies only what is pending, and DatabaseSeeder's three
     * seeders all find-or-create (AdminUserSeeder in particular only sets a
     * password on first creation, so a changed one survives).
     */
    private function baseline(): int
    {
        $this->info('Applying migrations...');

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('migrate failed - stopping before anything is deleted.');

            return self::FAILURE;
        }

        $this->info('Seeding platform baseline (roles, install tenant, admin)...');

        if ($this->call('db:seed', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Baseline seed failed - stopping before anything is deleted.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $doomedSlugs
     */
    private function removeDemoTenants(array $doomedSlugs): int
    {
        foreach ($doomedSlugs as $slug) {
            // withTrashed: DemoSeeder looks the tenant up without it, so a
            // soft-deleted demo tenant left behind would make the reseed
            // collide with the slug unique index.
            if (! Tenant::withTrashed()->where('slug', $slug)->exists()) {
                $this->line("  No tenant [{$slug}] - nothing to remove.");

                continue;
            }

            $code = $this->call('tenants:remove', [
                'tenant' => $slug,
                '--force' => true,
                '--dry-run' => (bool) $this->option('dry-run'),
                '--keep-assets' => (bool) $this->option('keep-assets'),
            ]);

            if ($code !== self::SUCCESS) {
                $this->error("tenants:remove {$slug} failed - stopping before the reseed.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
