<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * storage:prune-orphans - the sweep for files no row points at.
 *
 * The risk here is the opposite of the usual one: deleting too much. A file
 * that is still referenced, or that lives outside the upload directories, must
 * survive, so those cases are the ones worth pinning down.
 */
class PruneOrphanAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $tenant = Tenant::factory()->create(['slug' => 'prune-test']);
        app(TenantContext::class)->set($tenant);

        Storage::disk('public')->put('users/referenced.png', 'x');

        User::create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner',
            'email' => 'owner@prune-test.test',
            'password' => Hash::make('secret-password'),
            'image_url' => 'users/referenced.png',
        ]);
    }

    private function stale(string $path): void
    {
        Storage::disk('public')->put($path, 'x');

        // Older than the in-flight window the command leaves alone.
        touch(Storage::disk('public')->path($path), time() - 7200);
    }

    public function test_it_deletes_a_file_no_row_points_at(): void
    {
        $this->stale('foods/orphan.png');

        $this->artisan('storage:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing('foods/orphan.png');
    }

    public function test_it_keeps_a_file_that_is_still_referenced(): void
    {
        $this->artisan('storage:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('users/referenced.png');
    }

    public function test_it_keeps_a_recent_file_that_may_still_be_uploading(): void
    {
        // Written now, not backdated - a row may be moments from existing.
        Storage::disk('public')->put('foods/just-uploaded.png', 'x');

        $this->artisan('storage:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('foods/just-uploaded.png');
    }

    public function test_it_ignores_files_outside_the_upload_directories(): void
    {
        // Deliberately placed and referenced from code, not from a row.
        $this->stale('brochures/menu.pdf');

        $this->artisan('storage:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('brochures/menu.pdf');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->stale('foods/orphan.png');

        $this->artisan('storage:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('foods/orphan.png');
    }
}
