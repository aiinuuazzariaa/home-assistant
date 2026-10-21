<?php

declare(strict_types=1);

namespace Tests\Unit;

use DataCleaningService;
use PHPUnit\Framework\TestCase;

final class DataCleaningServiceTest extends TestCase
{
    private DataCleaningService $service;

    protected function setUp(): void
    {
        $this->service = new DataCleaningService();
    }

    public function testStandardizeValueConversions(): void
    {
        // 32°F -> 0°C
        $this->assertSame(0.0, $this->service->standardizeValue(32.0, '°F', '°C'));
        // 212°F -> 100°C
        $this->assertSame(100.0, $this->service->standardizeValue(212.0, '°F', '°C'));
        // 101325 Pa -> 1013.25 hPa
        $this->assertSame(1013.25, $this->service->standardizeValue(101325.0, 'Pa', 'hPa'));
        // 2500 W -> 2.5 kW
        $this->assertSame(2.5, $this->service->standardizeValue(2500.0, 'W', 'kW'));
    }

    public function testCleaningSummary(): void
    {
        $readings = [
            ['status' => 'normal'],
            ['status' => 'normal'],
            ['status' => 'missing'],
            ['status' => 'imputed'],
            ['status' => 'candidate_anomaly'],
            ['status' => 'invalid'],
        ];

        $summary = $this->service->cleaningSummary($readings);

        $this->assertSame(6, $summary['total']);
        $this->assertSame(2, $summary['normal']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame(1, $summary['imputed']);
        $this->assertSame(1, $summary['anomalies']);
        $this->assertSame(1, $summary['invalid']);
    }
}
