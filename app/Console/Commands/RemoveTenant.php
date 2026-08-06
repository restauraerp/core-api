<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Erases a tenant: every row that carries its tenant_id, the auth rows that
 * hang off its users, and the files its records point at.
 *
 * Most of the row deletion is done by the database - every domain table has a
 * cascading FK to tenants (see the add_tenant_id_to_domain_tables migration) -
 * so this command deletes the tenant and then sweeps up the few tables the
 * cascade cannot reach.
 */
class RemoveTenant extends Command
{
    protected $signature = 'tenants:remove
        {tenant : Tenant ID or slug}
        {--force : Skip the confirmation prompt}
        {--keep-assets : Delete the rows but leave uploaded files on disk}
        {--dry-run : Report what would be removed without deleting anything}';

    protected $description = 'Permanently remove a tenant, all of its data and its uploaded assets [--force --keep-assets --dry-run]';

    protected $help = <<<'HELP'
        Deletes the tenant row, every row in every table carrying its tenant_id, the
        sessions and API tokens belonging to its users, and the uploaded files its
        records point at. There is no undo - this is a force delete, not a soft one.

        Assets shared with another tenant (seeded demo images are) are kept. Files are
        removed only after the database changes commit.

        Prints a summary of the rows removed per table and the number of asset files
        removed.

        Examples:
          <info>php artisan tenants:remove 7 --dry-run</info>      report only, delete nothing
          <info>php artisan tenants:remove throwaway-qa</info>      prompt, then remove
          <info>php artisan tenants:remove 7 --force</info>         no prompt (use in scripts)
          <info>php artisan tenants:remove 7 --keep-assets</info>   drop the rows, keep the files
        HELP;

    /**
     * Columns holding a path on the `public` disk. Uploads land in shared
     * folders (foods/, users/, locations/, ...) rather than a per-tenant
     * prefix, so assets have to be resolved row by row - there is no directory
     * to drop.
     *
     * Deliberately excluded: cctv_cameras.stream_url, locations.map_url and
     * social_links.url all hold third-party URLs, not uploads.
     */
    private const ASSET_COLUMNS = [
        'images' => ['url'],
        'location_media' => ['url'],
        'product_media' => ['url'],
        'product_categories' => ['image_url'],
        'inventory_items' => ['image'],
        'users' => ['image_url'],
        'expenses' => ['receipt_url'],
    ];

    /**
     * website_settings is key/value, so its assets live in rows rather than
     * columns.
     */
    private const ASSET_SETTING_KEYS = ['logo_url', 'favicon_url', 'cover_image_url'];

    public function handle(): int
    {
        $tenant = $this->resolveTenant($this->argument('tenant'));

        if ($tenant === null) {
            $this->error("No tenant matches [{$this->argument('tenant')}].");

            return self::FAILURE;
        }

        $tenantId = $tenant->getKey();
        $userIds = DB::table('users')->where('tenant_id', $tenantId)->pluck('id')->all();

        $rowCounts = $this->rowCounts($tenantId, $userIds);
        $assets = $this->planAssets($tenantId);

        $this->line('');
        $this->line("Tenant #{$tenantId} \"{$tenant->name}\" (code: {$tenant->slug}, plan: {$tenant->plan}, status: {$tenant->status})");

        if ($this->option('dry-run')) {
            $this->report($rowCounts, $assets, 'would be removed');
            $this->comment('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->report($rowCounts, $assets, 'will be removed');
            $this->warn('This cannot be undone.');

            if (! $this->confirm("Permanently remove tenant [{$tenant->slug}]?", false)) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($tenant, $tenantId, $userIds) {
            // No FK ties these to the tenant - they hang off users, which are
            // about to disappear - so they have to go first.
            if ($userIds !== []) {
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }

            // Cascades through every domain table.
            $tenant->forceDelete();

            // Tables carrying tenant_id without a cascading FK of their own -
            // Spatie's model_has_* pivots. Safe now that their parents are gone.
            foreach ($this->tenantTables() as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });

        $deletedAssets = $this->option('keep-assets') ? [] : $this->deleteAssets($assets['delete']);

        $this->report($rowCounts, $assets, 'removed', $deletedAssets);

        return self::SUCCESS;
    }

    private function resolveTenant(string $identifier): ?Tenant
    {
        return Tenant::withTrashed()
            ->when(
                ctype_digit($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();
    }

    /**
     * Every table carrying tenant_id, discovered from the schema so a table
     * added later is covered without editing this command.
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->filter(fn (string $table) => $table !== 'tenants' && Schema::hasColumn($table, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Rows about to go, per table. Counted up front: once the cascade has run
     * there is nothing left to count.
     *
     * @param  list<int>  $userIds
     * @return array<string, int>
     */
    private function rowCounts(int $tenantId, array $userIds): array
    {
        $counts = [];

        foreach ($this->tenantTables() as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)->count();
        }

        if ($userIds !== []) {
            $counts['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->count();
            $counts['personal_access_tokens'] = DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->count();
        }

        return array_filter($counts);
    }

    /**
     * Splits this tenant's asset paths into what can be deleted and what must
     * be left alone.
     *
     * @return array{delete: list<string>, shared: list<string>, missing: list<string>}
     */
    private function planAssets(int $tenantId): array
    {
        $mine = [];
        $theirs = [];

        foreach (self::ASSET_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $query = fn (bool $ours) => DB::table($table)
                    ->where('tenant_id', $ours ? '=' : '!=', $tenantId)
                    ->whereNotNull($column)
                    ->pluck($column)
                    ->all();

                $mine = array_merge($mine, $query(true));
                $theirs = array_merge($theirs, $query(false));
            }
        }

        if (Schema::hasTable('website_settings')) {
            $settings = fn (bool $ours) => DB::table('website_settings')
                ->where('tenant_id', $ours ? '=' : '!=', $tenantId)
                ->whereIn('key', self::ASSET_SETTING_KEYS)
                ->pluck('value')
                ->all();

            $mine = array_merge($mine, $settings(true));
            $theirs = array_merge($theirs, $settings(false));
        }

        $mine = $this->normalisePaths($mine);
        $theirs = $this->normalisePaths($theirs);

        $disk = Storage::disk('public');
        $plan = ['delete' => [], 'shared' => [], 'missing' => []];

        foreach ($mine as $path) {
            // Demo and seeded data reuse the same file across tenants, so a
            // path another tenant still points at must survive this deletion.
            match (true) {
                in_array($path, $theirs, true) => $plan['shared'][] = $path,
                ! $disk->exists($path) => $plan['missing'][] = $path,
                default => $plan['delete'][] = $path,
            };
        }

        return $plan;
    }

    /**
     * @param  list<?string>  $values
     * @return list<string>
     */
    private function normalisePaths(array $values): array
    {
        return collect($values)
            ->map(fn (?string $value) => ltrim(trim((string) $value), '/'))
            ->filter()
            // Remote URLs are not ours to delete, and a traversal segment in a
            // stored path is not something to act on.
            ->reject(fn (string $value) => Str::startsWith($value, ['http://', 'https://', 'data:'])
                || Str::contains($value, '..'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function deleteAssets(array $paths): array
    {
        $disk = Storage::disk('public');
        $deleted = [];

        foreach ($paths as $path) {
            if ($disk->delete($path)) {
                $deleted[] = $path;

                continue;
            }

            $this->warn("  Could not delete asset: {$path}");
        }

        return $deleted;
    }

    /**
     * @param  array<string, int>  $rowCounts
     * @param  array{delete: list<string>, shared: list<string>, missing: list<string>}  $assets
     * @param  list<string>|null  $deletedAssets
     */
    private function report(array $rowCounts, array $assets, string $verb, ?array $deletedAssets = null): void
    {
        $this->line('');

        if ($rowCounts === []) {
            $this->line("No rows {$verb}.");
        } else {
            arsort($rowCounts);

            $this->table(
                ['Table', "Rows {$verb}"],
                collect($rowCounts)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all(),
            );

            $this->line('  '.array_sum($rowCounts)." row(s) {$verb} across ".count($rowCounts).' table(s).');
        }

        $assetCount = $deletedAssets !== null ? count($deletedAssets) : count($assets['delete']);

        if ($this->option('keep-assets')) {
            $this->line('  Assets kept on disk (--keep-assets): '.count($assets['delete']).' file(s).');
        } else {
            $this->line("  {$assetCount} asset file(s) {$verb}.");
        }

        if ($assets['shared'] !== []) {
            $this->line('  '.count($assets['shared']).' asset(s) kept - still referenced by another tenant.');
        }

        if ($assets['missing'] !== []) {
            $this->line('  '.count($assets['missing']).' referenced asset(s) were already absent from disk.');
        }

        $this->line('');
    }
}
