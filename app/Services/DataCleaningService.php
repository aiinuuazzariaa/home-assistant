<?php

/**
 * Service: DataCleaningService
 *
 * Tanggung jawab:
 *   - Menjalankan standardisasi nilai (satuan, format)
 *   - Menerapkan imputasi untuk missing value yang layak
 *   - Menangani anomali (tandai, koreksi jika ada dasar)
 *
 * Prinsip: Data asli tetap tersimpan. Status & flag mengindikasikan
 * kondisi data tanpa menghapus rekaman mentah.
 */

require_once __DIR__ . '/MissingValueService.php';
require_once __DIR__ . '/AnomalyDetectionService.php';
require_once __DIR__ . '/../Models/SensorReading.php';

class DataCleaningService
{
    private MissingValueService    $missingService;
    private AnomalyDetectionService $anomalyService;

    public function __construct()
    {
        $this->missingService = new MissingValueService();
        $this->anomalyService = new AnomalyDetectionService();
    }

    // -----------------------------------------------------------------------
    // Pipeline cleaning utama untuk satu entitas
    //
    // @param array  $rawReadings    - data mentah dari HA (sudah terurut ascending)
    // @param string $measurementType - dari sensor_mapping.measurement_type
    // @param int    $expectedInterval - interval harapan dalam detik
    // @return array                 - readings siap disimpan ke DB
    // -----------------------------------------------------------------------
    public function clean(array $rawReadings, string $measurementType = '', int $expectedInterval = 300): array
    {
        // STEP 1: Proses setiap reading — cek missing value
        $processed = [];
        foreach ($rawReadings as $raw) {
            $reading             = $this->missingService->processReading($raw['state'] ?? $raw['value'] ?? null);
            $reading['entity_id']   = $raw['entity_id']   ?? '';
            $reading['recorded_at'] = $raw['recorded_at'] ?? $raw['last_changed'] ?? date('Y-m-d H:i:s');
            $reading['is_anomaly']  = false;
            $reading['anomaly_type']= null;
            $processed[] = $reading;
        }

        // STEP 2: Deteksi timestamp gap dan imputasi untuk gap pendek
        $gapsFilled = $this->missingService->imputeLinear($processed, $expectedInterval);

        // STEP 3: Deteksi anomali pada seluruh readings (termasuk yang diimputasi)
        $cleaned = $this->anomalyService->detectAll($gapsFilled, $measurementType);

        return $cleaned;
    }

    // -----------------------------------------------------------------------
    // Persist hasil cleaning ke database
    // -----------------------------------------------------------------------
    public function persist(array $cleanedReadings, ?int $sensorMappingId = null): int
    {
        $records = [];
        foreach ($cleanedReadings as $reading) {
            $records[] = [
                'sensor_mapping_id' => $sensorMappingId,
                'entity_id'         => $reading['entity_id']    ?? '',
                'value'             => $reading['value']         ?? null,
                'raw_value'         => $reading['raw_value']     ?? null,
                'status'            => $reading['status']        ?? 'normal',
                'is_imputed'        => $reading['is_imputed']    ?? false,
                'is_anomaly'        => $reading['is_anomaly']    ?? false,
                'anomaly_type'      => $reading['anomaly_type']  ?? null,
                'recorded_at'       => $reading['recorded_at']   ?? date('Y-m-d H:i:s'),
            ];
        }

        return SensorReading::insertBatch($records);
    }

    // -----------------------------------------------------------------------
    // Standardisasi nilai — konversi satuan jika diperlukan
    // Contoh: °F → °C, mbar → hPa
    // -----------------------------------------------------------------------
    public function standardizeValue(float $value, string $fromUnit, string $toUnit): float
    {
        $key = strtolower(trim($fromUnit)) . '→' . strtolower(trim($toUnit));
        return match ($key) {
            '°f→°c'      => round(($value - 32) * 5 / 9, 4),
            'f→c'        => round(($value - 32) * 5 / 9, 4),
            'mbar→hpa'   => $value, // 1 mbar == 1 hPa
            'pa→hpa'     => round($value / 100, 4),
            'w→kw'       => round($value / 1000, 4),
            'kwh→wh'     => round($value * 1000, 4),
            default      => $value,
        };
    }

    // -----------------------------------------------------------------------
    // Laporan ringkasan cleaning
    // -----------------------------------------------------------------------
    public function cleaningSummary(array $cleanedReadings): array
    {
        $total          = count($cleanedReadings);
        $missing        = 0;
        $imputed        = 0;
        $anomalies      = 0;
        $invalid        = 0;
        $normal         = 0;

        foreach ($cleanedReadings as $r) {
            switch ($r['status'] ?? '') {
                case 'missing':           $missing++;   break;
                case 'imputed':           $imputed++;   break;
                case 'candidate_anomaly': $anomalies++; break;
                case 'invalid':           $invalid++;   break;
                default:                  $normal++;    break;
            }
        }

        return [
            'total'     => $total,
            'normal'    => $normal,
            'missing'   => $missing,
            'imputed'   => $imputed,
            'anomalies' => $anomalies,
            'invalid'   => $invalid,
        ];
    }
}
