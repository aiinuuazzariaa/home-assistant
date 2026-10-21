<?php

/**
 * Job: ProcessSensorData
 *
 * Orchestrator utama pipeline preprocessing sensor IoT.
 *
 * Alur yang dijalankan:
 *   Home Assistant → Raw Sensor Data
 *       → Identifikasi & Standardisasi Sensor (SensorMappingService)
 *       → Deteksi Missing Value (MissingValueService)
 *       → Deteksi Anomali (AnomalyDetectionService)
 *       → Data Cleaning (DataCleaningService)
 *       → Data Quality Assessment (DataQualityService)
 *       → Simpan ke Database Bersih
 *
 * Dapat dipanggil:
 *   - Via CLI: php ProcessSensorData.php [--date=YYYY-MM-DD]
 *   - Via HTTP: scheduler.php → include ProcessSensorData
 *   - Via cron/queue system
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config_api.php';
require_once __DIR__ . '/../Models/Sensor.php';
require_once __DIR__ . '/../Models/SensorReading.php';
require_once __DIR__ . '/../Models/SensorQuality.php';
require_once __DIR__ . '/../Services/SensorMappingService.php';
require_once __DIR__ . '/../Services/MissingValueService.php';
require_once __DIR__ . '/../Services/AnomalyDetectionService.php';
require_once __DIR__ . '/../Services/DataCleaningService.php';
require_once __DIR__ . '/../Services/DataQualityService.php';

set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Asia/Jakarta');

class ProcessSensorData
{
    private \PDO                  $db;
    private string                $haUrl;
    private string                $haToken;
    private SensorMappingService  $mappingService;
    private DataCleaningService   $cleaningService;
    private DataQualityService    $qualityService;
    private string                $targetDate;

    public function __construct(\PDO $db, string $haUrl, string $haToken, string $targetDate = null)
    {
        $this->db              = $db;
        $this->haUrl           = rtrim($haUrl, '/');
        $this->haToken         = $haToken;
        $this->mappingService  = new SensorMappingService();
        $this->cleaningService = new DataCleaningService();
        $this->qualityService  = new DataQualityService();
        if ($targetDate !== null) {
            $this->targetDate = $targetDate;
        } else {
            try {
                $latest = $db->query("SELECT to_char(to_timestamp(last_updated_ts), 'YYYY-MM-DD') FROM states ORDER BY last_updated_ts DESC LIMIT 1")->fetchColumn();
                $this->targetDate = $latest ?: date('Y-m-d', strtotime('-1 day'));
            } catch (\Throwable) {
                $this->targetDate = date('Y-m-d', strtotime('-1 day'));
            }
        }

        // Injeksi DB ke models
        Sensor::setDb($db);
        SensorReading::setDb($db);
        SensorQuality::setDb($db);
    }

    // -----------------------------------------------------------------------
    // Pastikan semua tabel yang dibutuhkan sudah ada
    // -----------------------------------------------------------------------
    public function initSchema(): void
    {
        Sensor::createTable();
        SensorReading::createTable();
        SensorQuality::createTable();
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS scheduler_logs (
                id         BIGSERIAL PRIMARY KEY,
                run_time   VARCHAR(255),
                status     VARCHAR(255),
                endpoint   VARCHAR(255),
                details    TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_scheduler_logs_run_time ON scheduler_logs(run_time DESC);
        ");
        $this->log('Schema initialized');
    }

    // -----------------------------------------------------------------------
    // Jalankan full pipeline
    // -----------------------------------------------------------------------
    public function run(): array
    {
        $this->log("=== ProcessSensorData START | date: {$this->targetDate} ===");
        $summary = ['date' => $this->targetDate, 'entities_processed' => 0, 'readings_saved' => 0, 'errors' => []];

        try {
            // STEP 1: Ambil data dari Home Assistant /api/states atau DB lokal
            $this->log('Fetching states (HA API or local DB fallback)...');
            $states = $this->getStates();

            if (empty($states)) {
                $this->log('No states available to process');
                return $summary;
            }
            $this->log('Fetched ' . count($states) . ' entities');

            // STEP 2: Identifikasi & Standardisasi Sensor
            $this->log('Running SensorMappingService...');
            $mappedSensors = $this->mappingService->processBatch($states);
            $this->log('Mapped ' . count($mappedSensors) . ' sensors');

            // STEP 3: Ambil history per sensor dan jalankan cleaning
            $this->log('Processing readings per sensor...');
            foreach ($mappedSensors as $mapped) {
                try {
                    $readingsSaved = $this->processSingleSensor($mapped);
                    $summary['readings_saved'] += $readingsSaved;
                    if ($readingsSaved > 0) {
                        $summary['entities_processed']++;
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = $mapped['entity_id'] . ': ' . $e->getMessage();
                    $this->log('ERROR on ' . $mapped['entity_id'] . ': ' . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            $summary['errors'][] = 'FATAL: ' . $e->getMessage();
            $this->log('FATAL ERROR: ' . $e->getMessage());
        }

        $this->log("=== ProcessSensorData DONE | entities: {$summary['entities_processed']} | readings: {$summary['readings_saved']} ===");
        return $summary;
    }

    // -----------------------------------------------------------------------
    // Ambil states (HA API dengan fallback tabel DB lokal)
    // -----------------------------------------------------------------------
    private function getStates(): array
    {
        try {
            $states = $this->fetchHaApi('/api/states');
            if (!empty($states) && is_array($states)) {
                return $states;
            }
        } catch (\Throwable $e) {
            $this->log("HA API unavailable ({$e->getMessage()}), using local DB fallback");
        }

        try {
            $sql = "
                SELECT sm.entity_id, s.state, sa.shared_attrs
                FROM states_meta sm
                JOIN (
                    SELECT metadata_id, max(state_id) as max_sid
                    FROM states
                    GROUP BY metadata_id
                ) latest ON sm.metadata_id = latest.metadata_id
                JOIN states s ON s.state_id = latest.max_sid
                LEFT JOIN state_attributes sa ON s.attributes_id = sa.attributes_id
                WHERE sm.entity_id LIKE 'sensor.%' OR sm.entity_id LIKE 'binary_sensor.%'
            ";
            $rows = $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            $states = [];
            foreach ($rows as $r) {
                $attrs = [];
                if (!empty($r['shared_attrs'])) {
                    $attrs = json_decode($r['shared_attrs'], true) ?: [];
                }
                $states[] = [
                    'entity_id'  => $r['entity_id'],
                    'state'      => $r['state'],
                    'attributes' => $attrs,
                ];
            }
            return $states;
        } catch (\Throwable $e) {
            $this->log("DB fallback states error: " . $e->getMessage());
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Proses satu sensor: ambil history → clean → quality assess → save
    // -----------------------------------------------------------------------
    private function processSingleSensor(array $mapped): int
    {
        $entityId       = $mapped['entity_id'];
        $measureType    = $mapped['measurement_type'] ?? '';
        $sensorRow      = Sensor::findByEntityId($entityId);
        $sensorMappingId= $sensorRow['id'] ?? null;

        $rawReadings = [];

        // 1. Coba ambil history dari HA API
        $periodStart = $this->targetDate . 'T00:00:00';
        $historyUrl  = "/api/history/period/{$periodStart}?filter_entity_id={$entityId}&end_time={$this->targetDate}T23:59:59";

        try {
            $history = $this->fetchHaApi($historyUrl);
            if (!empty($history) && is_array($history[0])) {
                foreach ($history[0] as $entry) {
                    $rawReadings[] = [
                        'entity_id'   => $entityId,
                        'state'       => $entry['state'] ?? null,
                        'recorded_at' => isset($entry['last_changed'])
                            ? date('Y-m-d H:i:s', strtotime($entry['last_changed']))
                            : date('Y-m-d H:i:s'),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // HA API offline
        }

        // 2. Fallback: ambil history dari tabel states jika HA API kosong
        if (empty($rawReadings)) {
            try {
                $startTs = strtotime($this->targetDate . ' 00:00:00');
                $endTs   = strtotime($this->targetDate . ' 23:59:59');

                $stmt = $this->db->prepare("
                    SELECT s.state, to_char(to_timestamp(s.last_updated_ts), 'YYYY-MM-DD HH24:MI:SS') as recorded_at
                    FROM states s
                    JOIN states_meta sm ON s.metadata_id = sm.metadata_id
                    WHERE sm.entity_id = ?
                      AND s.last_updated_ts >= ?
                      AND s.last_updated_ts <= ?
                    ORDER BY s.last_updated_ts ASC
                ");
                $stmt->execute([$entityId, $startTs, $endTs]);
                $dbRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($dbRows as $row) {
                    $rawReadings[] = [
                        'entity_id'   => $entityId,
                        'state'       => $row['state'],
                        'recorded_at' => $row['recorded_at'],
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore DB error
            }
        }

        if (empty($rawReadings)) {
            return 0;
        }

        // Hitung metrik SEBELUM cleaning (untuk perbandingan)
        $mvService     = new MissingValueService();
        $rawForQuality = array_map(fn($r) => [
            'value'      => is_numeric($r['state'] ?? '') ? (float) $r['state'] : null,
            'status'     => $mvService->isMissingValue($r['state'] ?? '') ? 'missing' : 'normal',
            'recorded_at'=> $r['recorded_at'],
        ], $rawReadings);

        $qualityBefore = [
            'completeness' => $this->qualityService->calculateCompleteness($rawForQuality)['completeness'],
            'validity'     => $this->qualityService->calculateValidity($rawForQuality)['validity'],
            'timeliness'   => $this->qualityService->calculateTimeliness($rawForQuality)['timeliness'],
        ];

        // Jalankan cleaning pipeline
        $cleanedReadings = $this->cleaningService->clean($rawReadings, $measureType);

        // Simpan ke database
        $saved = $this->cleaningService->persist($cleanedReadings, $sensorMappingId);

        // Data Quality Assessment SETELAH cleaning
        $this->qualityService->assessEntity(
            $entityId,
            $cleanedReadings,
            $this->targetDate . ' 00:00:00',
            $this->targetDate . ' 23:59:59'
        );

        $this->log("  [{$entityId}] raw=" . count($rawReadings) . " → cleaned={$saved} | before_completeness={$qualityBefore['completeness']}%", false);

        return $saved;
    }

    // -----------------------------------------------------------------------
    // HTTP GET ke Home Assistant REST API
    // -----------------------------------------------------------------------
    private function fetchHaApi(string $endpoint): mixed
    {
        $options = [
            'http' => [
                'header'        => "Authorization: Bearer {$this->haToken}\r\nContent-Type: application/json\r\n",
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ];
        $context  = stream_context_create($options);
        $url      = $this->haUrl . '/' . ltrim($endpoint, '/');
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException("HTTP request failed: {$endpoint}");
        }

        $statusLine = $http_response_header[0] ?? '';
        preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
        $statusCode = (int) ($match[1] ?? 0);

        if ($statusCode >= 400) {
            throw new \RuntimeException("API error {$statusCode} for {$endpoint}");
        }

        return json_decode($response, true) ?? [];
    }

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------
    private function log(string $message, bool $saveToDb = true): void
    {
        $ts = date('Y-m-d H:i:s');
        echo "[{$ts}] {$message}\n";

        if ($saveToDb) {
            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO scheduler_logs (run_time, status, details) VALUES (?, 'info', ?)"
                );
                $stmt->execute([$ts, $message]);
            } catch (\Throwable $e) {
                error_log("Log failed: " . $e->getMessage());
            }
        }
    }
}

// -----------------------------------------------------------------------
// Entry point: jalankan jika dipanggil langsung (CLI atau HTTP)
// -----------------------------------------------------------------------
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Parse tanggal dari argumen CLI atau query string
    $date = null;
    if (php_sapi_name() === 'cli') {
        foreach ($argv ?? [] as $arg) {
            if (str_starts_with($arg, '--date=')) {
                $date = substr($arg, 7);
            }
        }
    } else {
        $date = $_GET['date'] ?? null;
    }

    $job = new ProcessSensorData($db, $ha_url, $ha_token, $date);
    $job->initSchema();
    $result = $job->run();

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
    }
}
