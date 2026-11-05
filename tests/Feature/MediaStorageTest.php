<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Assets\ManagedAssets;
use App\Support\Assets\Media;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uploads in the bucket, alongside the files already on this server.
 *
 * Switching MEDIA_DISK to s3 moves new uploads only. What was on the local
 * disk before - older uploads and the demo images - must keep being served,
 * found and deleted, and every client keeps asking for /storage/{path}.
 */
class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3', ['url' => 'https://bucket.example.test']);
        config([
            'filesystems.media_disk' => 's3',
            'filesystems.disks.s3.url' => 'https://bucket.example.test',
        ]);

        $this->tenant = Tenant::factory()->create(['slug' => 'media-test']);
    }

    #[Test]
    public function a_new_upload_goes_to_the_bucket_not_the_server(): void
    {
        [$location, $supplier, $rice] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            Location::create(['name' => 'Main Kitchen']),
            Supplier::create(['name' => 'Dhaka Wholesale']),
            InventoryItem::create(['title' => 'Basmati Rice', 'description' => '25kg sack', 'unit' => 'kg']),
        ]);
        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());

        // A mime-typed stand-in: UploadedFile::fake()->image() needs GD, which
        // the API container lacks.
        $response = $this->post('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'items' => [['inventory_item_id' => $rice->id, 'quantity' => 1, 'price' => 100]],
            'receipts' => [UploadedFile::fake()->create('invoice.jpg', 120, 'image/jpeg')],
        ])->assertCreated();

        $path = $response->json('receipts.0.url');
        $this->assertStringStartsWith('receipts/', $path, 'rows keep a bare path, not a bucket URL');
        Storage::disk('s3')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function uploads_stay_on_the_server_by_default(): void
    {
        config(['filesystems.media_disk' => 'public']);

        $this->assertSame('public', Media::disk());
        $this->assertFalse(Media::isRemote());
        $this->assertSame(['public'], Media::disks());
    }

    #[Test]
    public function a_file_is_found_and_deleted_on_whichever_disk_has_it(): void
    {
        Storage::disk('public')->put('foods/old-upload.jpg', 'x');
        Storage::disk('s3')->put('foods/new-upload.jpg', 'x');

        $this->assertSame(['public', 's3'], Media::disks());
        $this->assertTrue(Media::exists('foods/old-upload.jpg'));
        $this->assertTrue(Media::exists('foods/new-upload.jpg'));
        $this->assertFalse(Media::exists('foods/nowhere.jpg'));

        $assets = app(ManagedAssets::class);
        $this->assertTrue($assets->release('foods/old-upload.jpg'));
        $this->assertTrue($assets->release('foods/new-upload.jpg'));

        Storage::disk('public')->assertMissing('foods/old-upload.jpg');
        Storage::disk('s3')->assertMissing('foods/new-upload.jpg');
    }

    #[Test]
    public function orphans_are_swept_from_both_disks_and_referenced_files_survive(): void
    {
        Storage::disk('public')->put('foods/orphan-local.jpg', 'x');
        Storage::disk('s3')->put('foods/orphan-bucket.jpg', 'x');
        Storage::disk('s3')->put('users/kept.jpg', 'x');
        User::factory()->forTenant($this->tenant)->create(['image_url' => 'users/kept.jpg']);

        $this->artisan('storage:prune-orphans', ['--force' => true, '--older-than' => 0])->assertSuccessful();

        Storage::disk('public')->assertMissing('foods/orphan-local.jpg');
        Storage::disk('s3')->assertMissing('foods/orphan-bucket.jpg');
        Storage::disk('s3')->assertExists('users/kept.jpg');
    }

    #[Test]
    public function storage_urls_for_bucket_files_redirect_to_the_bucket(): void
    {
        $this->get('/storage/foods/new.jpg')
            ->assertRedirect('https://bucket.example.test/foods/new.jpg')
            ->assertHeader('Cache-Control', 'max-age=86400, public')
            ->assertCookieMissing(config('session.cookie'));
    }

    #[Test]
    public function a_private_bucket_gets_a_short_lived_signed_url(): void
    {
        config(['filesystems.disks.s3.url' => null]);
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration) => "https://signed.example.test/{$path}?expires=".$expiration->getTimestamp()
        );

        $location = $this->get('/storage/foods/new.jpg')->assertStatus(302)->headers->get('Location');

        $this->assertStringStartsWith('https://signed.example.test/foods/new.jpg?expires=', $location);
    }

    #[Test]
    public function a_local_file_is_served_from_the_server_even_with_the_bucket_on(): void
    {
        Storage::disk('public')->put('foods/demo-biryani.png', 'png-bytes');

        $response = $this->get('/storage/foods/demo-biryani.png')->assertOk();

        $this->assertSame('png-bytes', $response->baseResponse->getFile()->getContent());
    }

    #[Test]
    public function without_a_bucket_a_missing_file_is_a_404_and_traversal_is_refused(): void
    {
        $this->get('/storage/foods/../../.env')->assertNotFound();

        config(['filesystems.media_disk' => 'public']);
        $this->get('/storage/foods/missing.jpg')->assertNotFound();
    }
}
