<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlowQueryDetectionTest extends TestCase
{
    #[Test]
    public function slow_query_triggers_warning_log_when_exceeding_threshold(): void
    {
        Log::spy();

        // Set low threshold to reliably test detection
        config(['database.slow_query_threshold' => 10.0]);

        // Execute query that takes more than 10ms (using SLEEP)
        DB::select('SELECT SLEEP(0.02) as slept');

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message) {
                return str_contains($message, '[SLOW QUERY DETECTED]')
                    && str_contains($message, 'SELECT SLEEP')
                    && str_contains($message, 'ms');
            });
    }

    #[Test]
    public function fast_query_does_not_trigger_slow_query_warning(): void
    {
        Log::spy();

        // High threshold ensures standard SELECT 1 won't trigger
        config(['database.slow_query_threshold' => 5000.0]);

        DB::select('SELECT 1 as test');

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function test_case_assert_query_duration_less_than_passes_within_budget(): void
    {
        $result = $this->assertQueryDurationLessThan(function () {
            return DB::select('SELECT 1 as val');
        }, 1000.0);

        $this->assertNotEmpty($result);
        $this->assertEquals(1, $result[0]->val);
    }
}
