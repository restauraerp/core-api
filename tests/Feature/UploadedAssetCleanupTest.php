<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded files leaving the disk when nothing points at them any more.
 *
 * This is what keeps `tenants:remove` honest. That command finds a
 * restaurant's files through its rows, so a file whose row stopped
 * referencing it is invisible to the deletion and would survive it.
 */
class UploadedAssetCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = Tenant::factory()->create(['slug' => 'asset-test']);
        app(TenantContext::class)->set($this->tenant);
    }

    private function user(?string $image = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Owner',
            'email' => 'owner'.uniqid().'@asset-test.test',
            'password' => Hash::make('secret-password'),
            'image_url' => $image,
        ]);
    }

    public function test_replacing_an_image_deletes_the_one_it_replaced(): void
    {
        Storage::disk('public')->put('users/old.png', 'old');
        Storage::disk('public')->put('users/new.png', 'new');

        $user = $this->user('users/old.png');
        $user->update(['image_url' => 'users/new.png']);

        Storage::disk('public')->assertMissing('users/old.png');
        Storage::disk('public')->assertExists('users/new.png');
    }

    public function test_clearing_an_image_deletes_the_file(): void
    {
        Storage::disk('public')->put('users/old.png', 'old');

        $this->user('users/old.png')->update(['image_url' => null]);

        Storage::disk('public')->assertMissing('users/old.png');
    }

    public function test_saving_without_touching_the_image_leaves_the_file_alone(): void
    {
        Storage::disk('public')->put('users/keep.png', 'x');

        $user = $this->user('users/keep.png');
        $user->update(['name' => 'Renamed']);

        Storage::disk('public')->assertExists('users/keep.png');
    }

    public function test_it_keeps_a_file_another_row_still_points_at(): void
    {
        // Seeded and demo imagery is deliberately reused, including across
        // restaurants - one row letting go of it must not blank out the rest.
        Storage::disk('public')->put('foods/shared.png', 'x');

        $keeper = ProductCategory::create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Keeper',
            'image_url' => 'foods/shared.png',
        ]);

        $letting_go = ProductCategory::create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Letting go',
            'image_url' => 'foods/shared.png',
        ]);

        $letting_go->update(['image_url' => null]);

        Storage::disk('public')->assertExists('foods/shared.png');
        $this->assertSame('foods/shared.png', $keeper->fresh()->image_url);
    }

    public function test_deleting_a_row_deletes_its_file(): void
    {
        Storage::disk('public')->put('foods/gone.png', 'x');

        Image::create([
            'tenant_id' => $this->tenant->getKey(),
            'url' => 'foods/gone.png',
            'imageable_id' => $this->user()->getKey(),
            'imageable_type' => User::class,
            'type' => 'image',
        ])->delete();

        Storage::disk('public')->assertMissing('foods/gone.png');
    }

    public function test_a_remote_url_is_never_touched(): void
    {
        $user = $this->user('https://cdn.example.test/avatar.png');

        // Nothing to assert on disk - what matters is that this does not throw
        // and does not try to resolve a URL as a path.
        $user->update(['image_url' => null]);

        $this->assertNull($user->fresh()->image_url);
    }
}
