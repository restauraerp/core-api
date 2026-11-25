<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assert that executing a callback takes no longer than the given maximum duration in milliseconds.
     */
    public function assertQueryDurationLessThan(callable $callback, float $maxMilliseconds): mixed
    {
        $start = microtime(true);
        $result = $callback();
        $duration = (microtime(true) - $start) * 1000.0;
        $this->assertLessThanOrEqual(
            $maxMilliseconds,
            $duration,
            "Operation duration of {$duration}ms exceeded allowed threshold of {$maxMilliseconds}ms."
        );

        return $result;
    }
}
