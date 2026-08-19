<?php

namespace Tests\Feature;

use App\Support\Demo\SeededIdSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demo seed reserves 200,000 ids per table and used never to give them
 * back, on a job that runs nightly.
 */
class SeededIdSpaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // information_schema caches AUTO_INCREMENT for a day by default, which
        // would have these assertions reading a stale number.
        DB::statement('SET SESSION information_schema_stats_expiry = 0');
    }

    private function autoIncrement(string $table): int
    {
        return (int) DB::selectOne(
            'SELECT AUTO_INCREMENT ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        )->ai;
    }

    #[Test]
    public function it_puts_each_counter_back_to_one_above_the_highest_row(): void
    {
        foreach (SeededIdSpace::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 5000000");
            $this->assertGreaterThan(1_000_000, $this->autoIncrement($table), "{$table} starts burned");
        }

        SeededIdSpace::reclaim();

        foreach (SeededIdSpace::TABLES as $table) {
            $highest = (int) DB::table($table)->max('id');

            $this->assertSame(
                $highest + 1,
                $this->autoIncrement($table),
                "{$table} should sit one above its highest row, not millions above",
            );
        }
    }

    /**
     * MySQL ignores an ALTER that would lower the counter beneath existing
     * rows, so reclaiming can never hand out an id that is already taken.
     */
    #[Test]
    public function it_never_lowers_a_counter_beneath_rows_that_exist(): void
    {
        // orders.location_id is NOT NULL, so the row needs a real outlet behind it.
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Id Space', 'slug' => 'id-space', 'plan' => 'enterprise',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = DB::table('locations')->insertGetId([
            'tenant_id' => $tenantId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('orders')->insert([
            'id' => 900,
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'order_type' => 'takeaway',
            'status' => 'served',
            'subtotal' => 100,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SeededIdSpace::reclaim();

        $this->assertGreaterThan(900, $this->autoIncrement('orders'));
    }
}
