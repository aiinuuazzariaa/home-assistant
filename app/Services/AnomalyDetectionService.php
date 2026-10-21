<?php

/**
 * Service: AnomalyDetectionService
 *
 * Tanggung jawab:
 *   - Statistical Validation (IQR — Interquartile Range)
 *   - Domain Validation (batas fisik per jenis sensor)
 *   - Temporal Validation (perubahan nilai antar-timestamp)
 *
 * Hasil deteksi diberi status 'candidate_anomaly' atau 'invalid'.
 * Sistem TIDAK langsung menghapus, hanya menandai.
 */

class AnomalyDetectionService
{
    // -----------------------------------------------------------------------
    // Batas domain per measurement_type
    // Format: [min, max]  — null berarti tidak dibatasi
    // -----------------------------------------------------------------------
    private array $domainBounds = [
        'Temperature'  => [-50.0, 85.0],
        'Humidity'     => [0.0, 100.0],
        'Pressure'     => [800.0, 1100.0],
        'Wind'         => [0.0, 200.0],
        'UV Index'     => [0.0, 20.0],
        'Rainfall'     => [0.0, 500.0],
        'Voltage'      => [0.0, 500.0],
        'Current'      => [0.0, 100.0],
        'Power'        => [0.0, 50000.0],
        'Energy'       => [0.0, null],
        'Frequency'    => [45.0, 65.0],
        'Moisture'     => [0.0, 100.0],
        'pH'           => [0.0, 14.0],
        'CO2'          => [300.0, 5000.0],
        'Illuminance'  => [0.0, 150000.0],
    ];

    // Faktor IQR untuk batas outlier (default 1.5 × IQR)
    private float $iqrFactor = 1.5;

    // Batas perubahan relatif per interval untuk temporal validation (%)
    // Nilai di atas ini dianggap "perubahan mendadak"
    private float $maxTemporalChangePct = 50.0;

    // -----------------------------------------------------------------------
    // 8.1 Statistical Validation — IQR
    //
    // @param array $readings  - ['value' => float, ...]
    // @return array           - readings dengan flag is_anomaly & anomaly_type
    // -----------------------------------------------------------------------
    public function statisticalValidation(array $readings): array
    {
        $values = array_filter(
            array_column($readings, 'value'),
            fn($v) => $v !== null
        );
        $values = array_values($values);

        if (count($values) < 4) {
            // Tidak cukup data untuk IQR
            return $readings;
        }

        sort($values);
        [$q1, $q3] = $this->calculateQuartiles($values);
        $iqr   = $q3 - $q1;
        $lower = $q1 - ($this->iqrFactor * $iqr);
        $upper = $q3 + ($this->iqrFactor * $iqr);

        foreach ($readings as &$reading) {
            if ($reading['value'] === null) {
                continue;
            }
            if ($reading['value'] < $lower || $reading['value'] > $upper) {
                $reading['is_anomaly']   = true;
                $reading['anomaly_type'] = 'statistical';
                if ($reading['status'] === 'normal') {
                    $reading['status'] = 'candidate_anomaly';
                }
            }
        }
        unset($reading);

        return $readings;
    }

    // -----------------------------------------------------------------------
    // 8.2 Domain Validation — batas fisik sensor
    //
    // @param array  $readings       - array readings
    // @param string $measurementType - jenis pengukuran (misal: 'Temperature')
    // @return array
    // -----------------------------------------------------------------------
    public function domainValidation(array $readings, string $measurementType): array
    {
        $bounds = $this->domainBounds[$measurementType] ?? null;

        if ($bounds === null) {
            return $readings; // Tidak ada aturan domain untuk jenis ini
        }

        [$min, $max] = $bounds;

        foreach ($readings as &$reading) {
            if ($reading['value'] === null) {
                continue;
            }

            $outOfBounds = ($min !== null && $reading['value'] < $min)
                        || ($max !== null && $reading['value'] > $max);

            if ($outOfBounds) {
                $reading['is_anomaly']   = true;
                $reading['anomaly_type'] = 'domain';
                $reading['status']       = 'invalid'; // domain violation = definitively invalid
            }
        }
        unset($reading);

        return $readings;
    }

    // -----------------------------------------------------------------------
    // 8.3 Temporal Validation — perubahan nilai mendadak antar-timestamp
    //
    // @param array $readings - readings terurut ascending
    // @return array
    // -----------------------------------------------------------------------
    public function temporalValidation(array $readings): array
    {
        for ($i = 1; $i < count($readings); $i++) {
            $prev    = $readings[$i - 1];
            $current = $readings[$i];

            if ($prev['value'] === null || $current['value'] === null) {
                continue;
            }

            $prevVal    = (float) $prev['value'];
            $currentVal = (float) $current['value'];

            // Hindari division by zero
            if (abs($prevVal) < 0.001) {
                continue;
            }

            $changePct = abs(($currentVal - $prevVal) / $prevVal) * 100;

            if ($changePct > $this->maxTemporalChangePct) {
                $readings[$i]['is_anomaly']   = true;
                $readings[$i]['anomaly_type'] = 'temporal';
                if ($readings[$i]['status'] === 'normal') {
                    $readings[$i]['status'] = 'candidate_anomaly';
                }
            }
        }

        return $readings;
    }

    // -----------------------------------------------------------------------
    // Jalankan semua deteksi sekaligus
    // -----------------------------------------------------------------------
    public function detectAll(array $readings, string $measurementType = ''): array
    {
        $readings = $this->statisticalValidation($readings);
        if ($measurementType) {
            $readings = $this->domainValidation($readings, $measurementType);
        }
        $readings = $this->temporalValidation($readings);
        return $readings;
    }

    // -----------------------------------------------------------------------
    // Hitung Q1 dan Q3 dari array nilai yang sudah tersort
    // -----------------------------------------------------------------------
    private function calculateQuartiles(array $sortedValues): array
    {
        $n  = count($sortedValues);
        $q1 = $this->percentile($sortedValues, 25);
        $q3 = $this->percentile($sortedValues, 75);
        return [$q1, $q3];
    }

    private function percentile(array $sortedArr, float $pct): float
    {
        $n   = count($sortedArr);
        $idx = ($pct / 100) * ($n - 1);
        $lo  = (int) floor($idx);
        $hi  = (int) ceil($idx);
        $frac = $idx - $lo;

        if ($hi >= $n) {
            $hi = $n - 1;
        }

        return $sortedArr[$lo] + $frac * ($sortedArr[$hi] - $sortedArr[$lo]);
    }

    // -----------------------------------------------------------------------
    // Ringkasan anomali
    // -----------------------------------------------------------------------
    public function getAnomalySummary(array $readings): array
    {
        $total       = count($readings);
        $anomalies   = array_filter($readings, fn($r) => $r['is_anomaly'] ?? false);
        $byType      = [];
        foreach ($anomalies as $r) {
            $type = $r['anomaly_type'] ?? 'unknown';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total'        => $total,
            'anomaly_count'=> count($anomalies),
            'anomaly_rate' => $total > 0 ? round((count($anomalies) / $total) * 100, 2) : 0.0,
            'by_type'      => $byType,
        ];
    }
}
