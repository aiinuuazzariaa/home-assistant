<?php

/**
 * Service: MissingValueService
 *
 * Tanggung jawab:
 *   - Deteksi missing value: NULL, empty string, 'unknown', 'unavailable'
 *   - Deteksi timestamp gap (pembacaan yang terlambat/hilang)
 *   - Imputasi (interpolasi linear berbasis waktu) untuk gap pendek
 *
 * Hasil missing value diberi status 'missing'.
 * Hasil imputasi diberi flag is_imputed = true dan status 'imputed'.
 */

class MissingValueService
{
    // Interval default yang diharapkan antar pembacaan (dalam detik)
    // Ubah sesuai konfigurasi sensor
    private int $defaultExpectedIntervalSeconds = 300; // 5 menit

    // Batas maksimum gap yang boleh diimputasi (dalam detik)
    // Gap lebih panjang dari ini TIDAK diimputasi
    private int $maxImputationGapSeconds = 1800; // 30 menit

    // -----------------------------------------------------------------------
    // Cek apakah nilai dianggap missing
    // -----------------------------------------------------------------------
    public function isMissingValue(mixed $value): bool
    {
        return $value === null
            || trim((string) $value) === ''
            || strtolower((string) $value) === 'unknown'
            || strtolower((string) $value) === 'unavailable'
            || strtolower((string) $value) === 'none';
    }

    // -----------------------------------------------------------------------
    // Proses satu pembacaan — tentukan status dan nilai numerik
    // -----------------------------------------------------------------------
    public function processReading(mixed $rawValue): array
    {
        if ($this->isMissingValue($rawValue)) {
            return [
                'value'      => null,
                'raw_value'  => (string) $rawValue,
                'status'     => 'missing',
                'is_imputed' => false,
            ];
        }

        $numericValue = is_numeric($rawValue) ? (float) $rawValue : null;

        return [
            'value'      => $numericValue,
            'raw_value'  => (string) $rawValue,
            'status'     => 'normal',
            'is_imputed' => false,
        ];
    }

    // -----------------------------------------------------------------------
    // Deteksi timestamp gap pada array readings yang sudah terurut ascending
    //
    // @param array $readings  - array ['recorded_at' => '...', 'value' => ...]
    // @param int   $expectedIntervalSec - interval yang diharapkan (detik)
    // @return array           - daftar gap: ['gap_start', 'gap_end', 'gap_seconds', 'missing_count']
    // -----------------------------------------------------------------------
    public function detectTimestampGaps(array $readings, int $expectedIntervalSec = null): array
    {
        $interval = $expectedIntervalSec ?? $this->defaultExpectedIntervalSeconds;
        $gaps     = [];

        for ($i = 1; $i < count($readings); $i++) {
            $prev    = $readings[$i - 1];
            $current = $readings[$i];

            $tPrev    = strtotime($prev['recorded_at']);
            $tCurrent = strtotime($current['recorded_at']);
            $diff     = $tCurrent - $tPrev;

            if ($diff > $interval * 1.5) { // toleransi 50%
                $missingCount = (int) floor($diff / $interval) - 1;
                $gaps[] = [
                    'gap_start'     => $prev['recorded_at'],
                    'gap_end'       => $current['recorded_at'],
                    'gap_seconds'   => $diff,
                    'missing_count' => max(0, $missingCount),
                ];
            }
        }

        return $gaps;
    }

    // -----------------------------------------------------------------------
    // Imputasi linear berbasis waktu untuk array readings
    //
    // Untuk setiap pasangan yang memiliki gap, interpolasi nilai pada titik tengah.
    // Hanya imputasi jika gap <= maxImputationGapSeconds.
    //
    // @param array $readings  - readings terurut ascending, field: recorded_at, value
    // @return array           - readings dengan data imputasi disisipkan
    // -----------------------------------------------------------------------
    public function imputeLinear(array $readings, int $expectedIntervalSec = null): array
    {
        $interval = $expectedIntervalSec ?? $this->defaultExpectedIntervalSeconds;
        $result   = [];

        for ($i = 0; $i < count($readings); $i++) {
            $result[] = $readings[$i];

            if ($i + 1 >= count($readings)) {
                break;
            }

            $prev    = $readings[$i];
            $next    = $readings[$i + 1];

            $t1 = strtotime($prev['recorded_at']);
            $t2 = strtotime($next['recorded_at']);
            $v1 = $prev['value'];
            $v2 = $next['value'];

            $gap = $t2 - $t1;

            // Jangan imputasi jika nilai salah satunya null atau gap terlalu panjang
            if ($v1 === null || $v2 === null || $gap > $this->maxImputationGapSeconds) {
                continue;
            }

            // Sisipkan titik-titik imputasi pada interval yang diharapkan
            $tStep = $t1 + $interval;
            while ($tStep < $t2 - ($interval * 0.5)) {
                // Interpolasi linear: x(t) = x1 + ((t - t1) / (t2 - t1)) * (x2 - x1)
                $ratio          = ($tStep - $t1) / ($t2 - $t1);
                $imputedValue   = $v1 + ($ratio * ($v2 - $v1));

                $result[] = [
                    'entity_id'   => $prev['entity_id']   ?? null,
                    'value'       => round($imputedValue, 4),
                    'raw_value'   => null,
                    'status'      => 'imputed',
                    'is_imputed'  => true,
                    'is_anomaly'  => false,
                    'anomaly_type'=> null,
                    'recorded_at' => date('Y-m-d H:i:s', $tStep),
                ];

                $tStep += $interval;
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Hitung statistik missing value untuk satu entity
    // -----------------------------------------------------------------------
    public function getMissingStats(array $readings): array
    {
        $total   = count($readings);
        $missing = count(array_filter($readings, fn($r) => $r['status'] === 'missing' || $r['value'] === null));
        $present = $total - $missing;

        return [
            'total'        => $total,
            'missing'      => $missing,
            'present'      => $present,
            'missing_rate' => $total > 0 ? round(($missing / $total) * 100, 2) : 0.0,
        ];
    }
}
