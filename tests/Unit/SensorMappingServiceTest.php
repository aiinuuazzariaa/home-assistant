<?php

declare(strict_types=1);

namespace Tests\Unit;

use SensorMappingService;
use PHPUnit\Framework\TestCase;

final class SensorMappingServiceTest extends TestCase
{
    private SensorMappingService $service;

    protected function setUp(): void
    {
        $this->service = new SensorMappingService();
    }

    public function testMapEntityWeatherTemperature(): void
    {
        $mapped = $this->service->mapEntity('sensor.garden_temperature_sensor');

        $this->assertSame('garden_temperature_sensor', $mapped['standard_name']);
        $this->assertSame('Weather', $mapped['category']);
        $this->assertSame('Temperature', $mapped['measurement_type']);
        $this->assertSame('°C', $mapped['unit']);
        $this->assertTrue($mapped['is_mapped']);
    }

    public function testMapEntityElectricalVoltage(): void
    {
        $mapped = $this->service->mapEntity('sensor.living_room_voltage');

        $this->assertSame('living_room_voltage', $mapped['standard_name']);
        $this->assertSame('Electrical', $mapped['category']);
        $this->assertSame('Voltage', $mapped['measurement_type']);
        $this->assertSame('V', $mapped['unit']);
        $this->assertTrue($mapped['is_mapped']);
    }

    public function testMapEntityAttributeUnitOverridesDefault(): void
    {
        $mapped = $this->service->mapEntity('sensor.ambient_temp', ['unit_of_measurement' => 'K']);

        $this->assertSame('K', $mapped['unit']);
        $this->assertSame('Temperature', $mapped['measurement_type']);
    }

    public function testMapEntityUnknownType(): void
    {
        $mapped = $this->service->mapEntity('sensor.unknown_custom_device');

        $this->assertSame('unknown_custom_device', $mapped['standard_name']);
        $this->assertSame('Unknown', $mapped['category']);
        $this->assertSame('Unknown', $mapped['measurement_type']);
        $this->assertFalse($mapped['is_mapped']);
    }
}
