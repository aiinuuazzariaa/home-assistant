<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnomalyDetectionService;
use PHPUnit\Framework\TestCase;

final class AnomalyDetectionServiceTest extends TestCase
{
    private AnomalyDetectionService $service;

    protected function setUp(): void
    {
        $this->service = new AnomalyDetectionService();
    }

    public function testDomainValidationMarksInvalidValues(): void
    {
        $readings = [
            ['value' => 25.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 150.0, 'status' => 'normal', 'is_anomaly' => false], // > 85.0 max for Temperature
            ['value' => -60.0, 'status' => 'normal', 'is_anomaly' => false], // < -50.0 min for Temperature
        ];

        $validated = $this->service->domainValidation($readings, 'Temperature');

        $this->assertFalse($validated[0]['is_anomaly']);
        $this->assertSame('normal', $validated[0]['status']);

        $this->assertTrue($validated[1]['is_anomaly']);
        $this->assertSame('domain', $validated[1]['anomaly_type']);
        $this->assertSame('invalid', $validated[1]['status']);

        $this->assertTrue($validated[2]['is_anomaly']);
        $this->assertSame('domain', $validated[2]['anomaly_type']);
        $this->assertSame('invalid', $validated[2]['status']);
    }

    public function testStatisticalValidationIdentifiesOutliers(): void
    {
        // 10 normal readings around 20-22, 1 extreme outlier 100
        $readings = [
            ['value' => 20.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 20.5, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 21.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 21.2, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 21.5, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 22.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 22.2, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 22.5, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 23.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 100.0, 'status' => 'normal', 'is_anomaly' => false], // Outlier
        ];

        $validated = $this->service->statisticalValidation($readings);

        $this->assertTrue($validated[9]['is_anomaly']);
        $this->assertSame('statistical', $validated[9]['anomaly_type']);
        $this->assertSame('candidate_anomaly', $validated[9]['status']);
        $this->assertFalse($validated[0]['is_anomaly']);
    }

    public function testTemporalValidationFlagsSuddenSpike(): void
    {
        $readings = [
            ['value' => 50.0, 'status' => 'normal', 'is_anomaly' => false],
            ['value' => 90.0, 'status' => 'normal', 'is_anomaly' => false], // 80% change > 50% maxTemporalChangePct
        ];

        $validated = $this->service->temporalValidation($readings);

        $this->assertFalse($validated[0]['is_anomaly']);
        $this->assertTrue($validated[1]['is_anomaly']);
        $this->assertSame('temporal', $validated[1]['anomaly_type']);
        $this->assertSame('candidate_anomaly', $validated[1]['status']);
    }
}
