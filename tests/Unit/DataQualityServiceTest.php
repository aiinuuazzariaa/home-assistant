<?php

declare(strict_types=1);

namespace Tests\Unit;

use DataQualityService;
use PHPUnit\Framework\TestCase;

final class DataQualityServiceTest extends TestCase
{
    private DataQualityService $service;

    protected function setUp(): void
    {
        $this->service = new DataQualityService();
    }

    public function testCalculateCompleteness(): void
    {
        $readings = [
            ['value' => 10.0, 'status' => 'normal'],
            ['value' => null, 'status' => 'missing'],
            ['value' => 12.0, 'status' => 'normal'],
            ['value' => 14.0, 'status' => 'normal'],
        ];

        $res = $this->service->calculateCompleteness($readings);

        $this->assertSame(4, $res['total_expected']);
        $this->assertSame(1, $res['total_missing']);
        $this->assertSame(3, $res['total_present']);
        $this->assertSame(75.0, $res['completeness']);
    }

    public function testCalculateValidity(): void
    {
        $readings = [
            ['value' => 10.0, 'status' => 'normal'],
            ['value' => null, 'status' => 'missing'], // Ignored in validity denominator
            ['value' => 999.0, 'status' => 'invalid'],
            ['value' => 14.0, 'status' => 'normal'],
        ];

        $res = $this->service->calculateValidity($readings);

        $this->assertSame(3, $res['total_examined']);
        $this->assertSame(2, $res['total_valid']);
        $this->assertSame(1, $res['total_invalid']);
        $this->assertSame(66.67, $res['validity']);
    }

    public function testCalculateTimeliness(): void
    {
        $readings = [
            ['recorded_at' => '2026-10-12 10:00:00'],
            ['recorded_at' => '2026-10-12 10:05:00'],
            ['recorded_at' => '2026-10-12 10:10:00'],
        ];

        $res = $this->service->calculateTimeliness($readings, 300);

        $this->assertSame(2, $res['expected_reports']);
        $this->assertSame(2, $res['on_time_reports']);
        $this->assertSame(100.0, $res['timeliness']);
    }
}
