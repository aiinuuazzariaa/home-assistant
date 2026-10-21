<?php

/**
 * Service: DataQualityService
 *
 * Tanggung jawab:
 *   - Menghitung empat dimensi kualitas data:
 *       1. Completeness  — kelengkapan data
 *       2. Validity      — keabsahan nilai
 *       3. Consistency   — konsistensi entitas
 *       4. Timeliness    — ketepatan interval pelaporan
 *   - Menyimpan hasil ke tabel sensor_quality
 *   - Menghasilkan laporan perbandingan sebelum/sesudah cleaning
 */

require_once __DIR__ . '/../Models/SensorQuality.php';
require_once __DIR__ . '/SensorMappingService.php';

class DataQualityService
{
    private SensorMappingService $mappingService;

    public function __construct()
    {
        $this->mappingService = new SensorMappingService();
    }

    // -----------------------------------------------------------------------
    // 10.1 Completeness — kelengkapan
    //
    // Rumus: (1 - jumlah_missing / total) × 100%
    // -----------------------------------------------------------------------
    public function calculateCompleteness(array $readings): array
    {
        $total   = count($readings);
        $missing = count(array_filter($readings, fn($r) => $r['status'] === 'missing' || $r['value'] === null));
        $present = $total - $missing;

        $completeness = $total > 0
            ? round((1 - $missing / $total) * 100, 2)
            : 0.0;

        return [
            'completeness'   => $completeness,
            'total_expected' => $total,
            'total_missing'  => $missing,
            'total_present'  => $present,
        ];
    }

    // -----------------------------------------------------------------------
    // 10.2 Validity — keabsahan nilai
    //
    // Rumus: (jumlah_valid / total_diperiksa) × 100%
    // Valid = status bukan 'invalid' dan bukan 'missing'
    // -----------------------------------------------------------------------
    public function calculateValidity(array $readings): array
    {
        $examined = array_filter($readings, fn($r) => $r['status'] !== 'missing');
        $total    = count($examined);

        $invalid  = count(array_filter($examined, fn($r) => $r['status'] === 'invalid'));
        $valid    = $total - $invalid;

        $validity = $total > 0
            ? round(($valid / $total) * 100, 2)
            : 0.0;

        return [
            'validity'      => $validity,
            'total_examined'=> $total,
            'total_valid'   => $valid,
            'total_invalid' => $invalid,
        ];
    }

    // -----------------------------------------------------------------------
    // 10.3 Consistency — konsistensi entitas
    //
    // Aspek yang diperiksa:
    //   - Naming consistency: apakah sudah termapping ke standar
    //   - Unit consistency: apakah unit tercatat
    //   - Category consistency: apakah kategori terisi
    // -----------------------------------------------------------------------
    public function calculateConsistency(): array
    {
        $namingStats = $this->mappingService->calculateNamingConsistency();

        $allSensors      = \Sensor::all();
        $total           = count($allSensors);
        $withUnit        = count(array_filter($allSensors, fn($s) => !empty($s['unit'])));
        $withCategory    = count(array_filter($allSensors, fn($s) => !empty($s['category'])));

        $namingConsistency   = $namingStats['consistency'];
        $unitConsistency     = $total > 0 ? round(($withUnit     / $total) * 100, 2) : 0.0;
        $categoryConsistency = $total > 0 ? round(($withCategory / $total) * 100, 2) : 0.0;

        // Overall consistency = rata-rata tiga aspek
        $overall = round(($namingConsistency + $unitConsistency + $categoryConsistency) / 3, 2);

        return [
            'consistency'              => $overall,
            'naming_consistency'       => $namingConsistency,
            'unit_consistency'         => $unitConsistency,
            'category_consistency'     => $categoryConsistency,
            'total_sensors'            => $total,
            'mapped_sensors'           => $namingStats['mapped'],
            'is_name_consistent'       => $namingConsistency >= 80.0,
            'is_unit_consistent'       => $unitConsistency   >= 80.0,
            'is_category_consistent'   => $categoryConsistency >= 80.0,
        ];
    }

    // -----------------------------------------------------------------------
    // 10.4 Timeliness — ketepatan waktu pelaporan
    //
    // Rumus: (laporan_tepat_waktu / laporan_diharapkan) × 100%
    //
    // @param array $readings          - readings terurut ascending
    // @param int   $expectedIntervalSec - interval yang diharapkan (detik)
    // -----------------------------------------------------------------------
    public function calculateTimeliness(array $readings, int $expectedIntervalSec = 300): array
    {
        if (count($readings) < 2) {
            return [
                'timeliness'       => 0.0,
                'expected_reports' => 0,
                'on_time_reports'  => 0,
            ];
        }

        $tFirst = strtotime($readings[0]['recorded_at']);
        $tLast  = strtotime($readings[array_key_last($readings)]['recorded_at']);

        $expectedReports = (int) floor(($tLast - $tFirst) / $expectedIntervalSec);
        $onTime          = 0;
        $tolerance       = $expectedIntervalSec * 0.5; // toleransi 50%

        for ($i = 1; $i < count($readings); $i++) {
            $diff = strtotime($readings[$i]['recorded_at']) - strtotime($readings[$i - 1]['recorded_at']);
            if ($diff <= $expectedIntervalSec + $tolerance) {
                $onTime++;
            }
        }

        $timeliness = $expectedReports > 0
            ? round(($onTime / $expectedReports) * 100, 2)
            : 0.0;

        return [
            'timeliness'       => min(100.0, $timeliness),
            'expected_reports' => $expectedReports,
            'on_time_reports'  => $onTime,
        ];
    }

    // -----------------------------------------------------------------------
    // Assess kualitas satu entitas — jalankan semua dimensi sekaligus
    // -----------------------------------------------------------------------
    public function assessEntity(
        string $entityId,
        array  $readings,
        string $periodStart,
        string $periodEnd,
        int    $expectedIntervalSec = 300
    ): array {
        $completeness = $this->calculateCompleteness($readings);
        $validity     = $this->calculateValidity($readings);
        $consistency  = $this->calculateConsistency();
        $timeliness   = $this->calculateTimeliness($readings, $expectedIntervalSec);

        $result = [
            'entity_id'   => $entityId,
            'period_start'=> $periodStart,
            'period_end'  => $periodEnd,

            // Completeness
            'completeness'   => $completeness['completeness'],
            'total_expected' => $completeness['total_expected'],
            'total_missing'  => $completeness['total_missing'],

            // Validity
            'validity'       => $validity['validity'],
            'total_valid'    => $validity['total_valid'],
            'total_invalid'  => $validity['total_invalid'],

            // Consistency
            'consistency'             => $consistency['consistency'],
            'is_name_consistent'      => $consistency['is_name_consistent'],
            'is_unit_consistent'      => $consistency['is_unit_consistent'],
            'is_category_consistent'  => $consistency['is_category_consistent'],

            // Timeliness
            'timeliness'       => $timeliness['timeliness'],
            'expected_reports' => $timeliness['expected_reports'],
            'on_time_reports'  => $timeliness['on_time_reports'],
        ];

        // Simpan ke database
        \SensorQuality::insert($result);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Laporan perbandingan sebelum/sesudah cleaning
    // -----------------------------------------------------------------------
    public function comparisonReport(array $before, array $after): array
    {
        return [
            'indicator'   => ['Completeness', 'Validity', 'Consistency', 'Timeliness'],
            'before'      => [
                $before['completeness'] ?? 0,
                $before['validity']     ?? 0,
                $before['consistency']  ?? 0,
                $before['timeliness']   ?? 0,
            ],
            'after'       => [
                $after['completeness'] ?? 0,
                $after['validity']     ?? 0,
                $after['consistency']  ?? 0,
                $after['timeliness']   ?? 0,
            ],
        ];
    }
}
