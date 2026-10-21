<?php

declare(strict_types=1);

namespace Tests\Unit;

use MissingValueService;
use PHPUnit\Framework\TestCase;

final class MissingValueServiceTest extends TestCase
{
    private MissingValueService $service;

    protected function setUp(): void
    {
        $this->service = new MissingValueService();
    }

    public function testIsMissingValueIdentifiesMissingTokens(): void
    {
        $this->assertTrue($this->service->isMissingValue(null));
        $this->assertTrue($this->service->isMissingValue(''));
        $this->assertTrue($this->service->isMissingValue('   '));
        $this->assertTrue($this->service->isMissingValue('unknown'));
        $this->assertTrue($this->service->isMissingValue('UNKNOWN'));
        $this->assertTrue($this->service->isMissingValue('unavailable'));
        $this->assertTrue($this->service->isMissingValue('none'));

        $this->assertFalse($this->service->isMissingValue('25.4'));
        $this->assertFalse($this->service->isMissingValue(0));
        $this->assertFalse($this->service->isMissingValue('0'));
    }

    public function testProcessReadingWithValidValue(): void
    {
        $res = $this->service->processReading('28.5');

        $this->assertSame(28.5, $res['value']);
        $this->assertSame('28.5', $res['raw_value']);
        $this->assertSame('normal', $res['status']);
        $this->assertFalse($res['is_imputed']);
    }

    public function testProcessReadingWithMissingValue(): void
    {
        $res = $this->service->processReading('unavailable');

        $this->assertNull($res['value']);
        $this->assertSame('unavailable', $res['raw_value']);
        $this->assertSame('missing', $res['status']);
        $this->assertFalse($res['is_imputed']);
    }

    public function testDetectTimestampGapsIdentifiesSignificantGap(): void
    {
        $readings = [
            ['recorded_at' => '2026-10-12 10:00:00', 'value' => 20.0],
            ['recorded_at' => '2026-10-12 10:20:00', 'value' => 22.0], // gap 1200s > 300 * 1.5
        ];

        $gaps = $this->service->detectTimestampGaps($readings, 300);

        $this->assertCount(1, $gaps);
        $this->assertSame(1200, $gaps[0]['gap_seconds']);
        $this->assertSame(3, $gaps[0]['missing_count']);
    }

    public function testImputeLinearInterpolatesIntermediatePoints(): void
    {
        $readings = [
            [
                'entity_id'   => 'sensor.temp',
                'recorded_at' => '2026-10-12 10:00:00',
                'value'       => 20.0,
                'status'      => 'normal',
                'is_imputed'  => false,
            ],
            [
                'entity_id'   => 'sensor.temp',
                'recorded_at' => '2026-10-12 10:10:00',
                'value'       => 30.0,
                'status'      => 'normal',
                'is_imputed'  => false,
            ],
        ];

        // Interval 300s (5 min), gap is 600s -> should insert 1 imputed point at 10:05:00 with value 25.0
        $result = $this->service->imputeLinear($readings, 300);

        $this->assertCount(3, $result);
        $this->assertSame('2026-10-12 10:05:00', $result[1]['recorded_at']);
        $this->assertSame(25.0, $result[1]['value']);
        $this->assertTrue($result[1]['is_imputed']);
        $this->assertSame('imputed', $result[1]['status']);
    }
}
