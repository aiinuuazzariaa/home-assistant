<?php

/**
 * Service: SensorMappingService
 *
 * Tanggung jawab:
 *   - Identifikasi sensor dari data mentah Home Assistant
 *   - Standardisasi entitas: penamaan, kategori, jenis pengukuran, satuan
 *   - Simpan/update hasil mapping ke tabel sensor_mapping
 *
 * Alur:
 *   Raw HA entity_id → normalisasi nama → cocokkan pattern → assign standar
 */

require_once __DIR__ . '/../Models/Sensor.php';

class SensorMappingService
{
    // -----------------------------------------------------------------------
    // Dictionary pattern mapping:
    // Kunci = pattern regex (case-insensitive) untuk mencocokkan entity_id
    // Nilai = informasi standar yang ditetapkan
    // -----------------------------------------------------------------------
    private array $mappingDictionary = [
        // Weather / Cuaca
        '/temp(erature)?/'      => ['category' => 'Weather',    'measurement_type' => 'Temperature', 'unit' => '°C'],
        '/humid(ity)?/'         => ['category' => 'Weather',    'measurement_type' => 'Humidity',    'unit' => '%'],
        '/pressure/'            => ['category' => 'Weather',    'measurement_type' => 'Pressure',    'unit' => 'hPa'],
        '/wind/'                => ['category' => 'Weather',    'measurement_type' => 'Wind',        'unit' => 'm/s'],
        '/uv/'                  => ['category' => 'Weather',    'measurement_type' => 'UV Index',    'unit' => 'UV'],
        '/rain|rainfall/'       => ['category' => 'Weather',    'measurement_type' => 'Rainfall',    'unit' => 'mm'],
        '/dew/'                 => ['category' => 'Weather',    'measurement_type' => 'Dew Point',   'unit' => '°C'],

        // Electrical / Listrik
        '/voltage/'             => ['category' => 'Electrical', 'measurement_type' => 'Voltage',     'unit' => 'V'],
        '/current/'             => ['category' => 'Electrical', 'measurement_type' => 'Current',     'unit' => 'A'],
        '/power/'               => ['category' => 'Electrical', 'measurement_type' => 'Power',       'unit' => 'W'],
        '/energy/'              => ['category' => 'Electrical', 'measurement_type' => 'Energy',      'unit' => 'kWh'],
        '/frequency/'           => ['category' => 'Electrical', 'measurement_type' => 'Frequency',   'unit' => 'Hz'],

        // Soil / Tanah
        '/moisture|soil.*moist/'=> ['category' => 'Soil',       'measurement_type' => 'Moisture',    'unit' => '%'],
        '/nitrogen/'            => ['category' => 'Soil',       'measurement_type' => 'Nitrogen',    'unit' => 'mg/kg'],
        '/phosph/'              => ['category' => 'Soil',       'measurement_type' => 'Phosphorus',  'unit' => 'mg/kg'],
        '/potassium|potasium/'  => ['category' => 'Soil',       'measurement_type' => 'Potassium',   'unit' => 'mg/kg'],
        '/conductivity/'        => ['category' => 'Soil',       'measurement_type' => 'Conductivity','unit' => 'µS/cm'],
        '/ph/'                  => ['category' => 'Soil',       'measurement_type' => 'pH',          'unit' => 'pH'],

        // Environment
        '/co2/'                 => ['category' => 'Environment','measurement_type' => 'CO2',         'unit' => 'ppm'],
        '/pm25|pm2_5/'          => ['category' => 'Environment','measurement_type' => 'PM2.5',       'unit' => 'µg/m³'],
        '/pm10/'                => ['category' => 'Environment','measurement_type' => 'PM10',        'unit' => 'µg/m³'],
        '/illuminance|lux/'     => ['category' => 'Environment','measurement_type' => 'Illuminance', 'unit' => 'lx'],
    ];

    // -----------------------------------------------------------------------
    // Identifikasi dan mapping satu entitas
    // -----------------------------------------------------------------------
    public function mapEntity(string $entityId, array $attributes = []): array
    {
        $standardName = $this->buildStandardName($entityId);
        $mapped       = $this->matchPattern($entityId);

        // Unit dari atribut HA lebih akurat jika tersedia
        $unit = $attributes['unit_of_measurement'] ?? $mapped['unit'] ?? '';

        return [
            'entity_id'        => $entityId,
            'standard_name'    => $standardName,
            'category'         => $mapped['category']         ?? 'Unknown',
            'measurement_type' => $mapped['measurement_type'] ?? 'Unknown',
            'unit'             => $unit,
            'is_mapped'        => $mapped !== [],
        ];
    }

    // -----------------------------------------------------------------------
    // Proses batch: mapping array entitas dari HA /api/states
    // -----------------------------------------------------------------------
    public function processBatch(array $haStates): array
    {
        $results = [];
        foreach ($haStates as $state) {
            $entityId   = $state['entity_id']  ?? '';
            $attributes = $state['attributes'] ?? [];

            // Filter hanya domain 'sensor' dan 'binary_sensor'
            $domain = explode('.', $entityId)[0] ?? '';
            if (!in_array($domain, ['sensor', 'binary_sensor'])) {
                continue;
            }

            $mapped = $this->mapEntity($entityId, $attributes);
            $mapped['current_state'] = $state['state'] ?? null;
            $mapped['last_updated']  = $state['last_updated'] ?? null;
            Sensor::upsert($mapped);
            $results[] = $mapped;
        }
        return $results;
    }

    // -----------------------------------------------------------------------
    // Bangun standard_name dari entity_id
    // Contoh: sensor.weather_temp_1 → weather_temperature_1
    // -----------------------------------------------------------------------
    private function buildStandardName(string $entityId): string
    {
        // Hapus domain prefix (sensor., binary_sensor., dll.)
        $name = preg_replace('/^[a-z_]+\./', '', $entityId);

        // Normalisasi separator
        $name = str_replace(['-', '__'], '_', $name);

        return strtolower($name);
    }

    // -----------------------------------------------------------------------
    // Cocokkan entity_id dengan dictionary pattern
    // -----------------------------------------------------------------------
    private function matchPattern(string $entityId): array
    {
        foreach ($this->mappingDictionary as $pattern => $info) {
            if (preg_match($pattern, $entityId, flags: PREG_UNMATCHED_AS_NULL)) {
                return $info;
            }
        }
        return [];
    }

    // -----------------------------------------------------------------------
    // Hitung Consistency: berapa % sensor yang sudah termapping
    // -----------------------------------------------------------------------
    public function calculateNamingConsistency(): array
    {
        $all    = Sensor::all();
        $total  = count($all);
        $mapped = count(array_filter($all, fn($s) => $s['is_mapped']));

        return [
            'total'       => $total,
            'mapped'      => $mapped,
            'unmapped'    => $total - $mapped,
            'consistency' => $total > 0 ? round(($mapped / $total) * 100, 2) : 0.0,
        ];
    }
}
