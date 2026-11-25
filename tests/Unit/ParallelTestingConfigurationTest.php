<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParallelTestingConfigurationTest extends TestCase
{
    #[Test]
    public function test_database_guardrail_regex_accepts_valid_test_databases(): void
    {
        $pattern = '/^restoraerp_test(_test_\d+)?$/';

        $this->assertSame(1, preg_match($pattern, 'restoraerp_test'));
        $this->assertSame(1, preg_match($pattern, 'restoraerp_test_test_1'));
        $this->assertSame(1, preg_match($pattern, 'restoraerp_test_test_2'));
        $this->assertSame(1, preg_match($pattern, 'restoraerp_test_test_16'));
    }

    #[Test]
    public function test_database_guardrail_regex_rejects_non_test_databases(): void
    {
        $pattern = '/^restoraerp_test(_test_\d+)?$/';

        $this->assertSame(0, preg_match($pattern, 'restoraerp'));
        $this->assertSame(0, preg_match($pattern, 'restoraerp_production'));
        $this->assertSame(0, preg_match($pattern, 'restoraerp_dev'));
        $this->assertSame(0, preg_match($pattern, 'other_test'));
        $this->assertSame(0, preg_match($pattern, 'restoraerp_test_invalid'));
    }
}
