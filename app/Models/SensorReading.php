<?php

/**
 * Model: SensorReading
 *
 * Menyimpan setiap pembacaan/data point dari sensor.
 * Mencakup nilai mentah, status, flag imputasi, dan flag anomali.
 *
 * Tabel DB: sensor_readings
 *
 * Status yang mungkin: normal | missing | candidate_anomaly | invalid | imputed | corrected
 */

class SensorReading
{
    public int    $id;
    public int    $sensor_mapping_id;   // FK ke sensor_mapping.id
    public string $entity_id;           // entity_id asli (untuk traceability)
    public ?float $value;               // nilai numerik (null jika missing)
    public ?string $raw_value;          // nilai mentah sebelum parsing (string asli dari HA)
    public string $status;              // normal|missing|candidate_anomaly|invalid|imputed|corrected
    public bool   $is_imputed;          // apakah nilai ini hasil imputasi
    public bool   $is_anomaly;          // apakah ditandai sebagai kandidat anomali
    public ?string $anomaly_type;       // statistical|domain|temporal
    public string $recorded_at;         // timestamp pembacaan asli (dari HA)
    public string $fetched_at;          // timestamp saat data diambil ke sistem ini

    private static \PDO $db;

    public static function setDb(\PDO $db): void
    {
        self::$db = $db;
    }

    // -----------------------------------------------------------------------
    // DDL — buat tabel jika belum ada
    // -----------------------------------------------------------------------
    public static function createTable(): void
    {
        self::$db->exec("
            CREATE TABLE IF NOT EXISTS sensor_readings (
                id                  BIGSERIAL PRIMARY KEY,
                sensor_mapping_id   BIGINT REFERENCES sensor_mapping(id),
                entity_id           VARCHAR(255) NOT NULL,
                value               DOUBLE PRECISION,
                raw_value           TEXT,
                status              VARCHAR(50) DEFAULT 'normal',
                is_imputed          BOOLEAN DEFAULT FALSE,
                is_anomaly          BOOLEAN DEFAULT FALSE,
                anomaly_type        VARCHAR(50),
                recorded_at         TIMESTAMP,
                fetched_at          TIMESTAMP DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_readings_entity ON sensor_readings(entity_id);
            CREATE INDEX IF NOT EXISTS idx_readings_recorded ON sensor_readings(recorded_at DESC);
            CREATE INDEX IF NOT EXISTS idx_readings_status ON sensor_readings(status);
            CREATE INDEX IF NOT EXISTS idx_readings_entity_recorded ON sensor_readings(entity_id, recorded_at DESC);
        ");
    }

    // -----------------------------------------------------------------------
    // Insert satu reading baru
    // -----------------------------------------------------------------------
    public static function insert(array $data): int
    {
        $stmt = self::$db->prepare("
            INSERT INTO sensor_readings
                (sensor_mapping_id, entity_id, value, raw_value, status, is_imputed, is_anomaly, anomaly_type, recorded_at)
            VALUES
                (:sensor_mapping_id, :entity_id, :value, :raw_value, :status, :is_imputed, :is_anomaly, :anomaly_type, :recorded_at)
            RETURNING id
        ");
        $stmt->execute([
            ':sensor_mapping_id' => $data['sensor_mapping_id'] ?? null,
            ':entity_id'         => $data['entity_id'],
            ':value'             => $data['value']             ?? null,
            ':raw_value'         => $data['raw_value']         ?? null,
            ':status'            => $data['status']            ?? 'normal',
            ':is_imputed'        => !empty($data['is_imputed']) ? 'true' : 'false',
            ':is_anomaly'        => !empty($data['is_anomaly']) ? 'true' : 'false',
            ':anomaly_type'      => $data['anomaly_type']      ?? null,
            ':recorded_at'       => $data['recorded_at']       ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Batch insert readings dengan transaksi (optimasi performa)
    // -----------------------------------------------------------------------
    public static function insertBatch(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $inTx = self::$db->inTransaction();
        if (!$inTx) {
            self::$db->beginTransaction();
        }

        try {
            $stmt = self::$db->prepare("
                INSERT INTO sensor_readings
                    (sensor_mapping_id, entity_id, value, raw_value, status, is_imputed, is_anomaly, anomaly_type, recorded_at)
                VALUES
                    (:sensor_mapping_id, :entity_id, :value, :raw_value, :status, :is_imputed, :is_anomaly, :anomaly_type, :recorded_at)
            ");

            $count = 0;
            foreach ($records as $data) {
                $stmt->execute([
                    ':sensor_mapping_id' => $data['sensor_mapping_id'] ?? null,
                    ':entity_id'         => $data['entity_id'],
                    ':value'             => $data['value']             ?? null,
                    ':raw_value'         => $data['raw_value']         ?? null,
                    ':status'            => $data['status']            ?? 'normal',
                    ':is_imputed'        => !empty($data['is_imputed']) ? 'true' : 'false',
                    ':is_anomaly'        => !empty($data['is_anomaly']) ? 'true' : 'false',
                    ':anomaly_type'      => $data['anomaly_type']      ?? null,
                    ':recorded_at'       => $data['recorded_at']       ?? date('Y-m-d H:i:s'),
                ]);
                $count++;
            }

            if (!$inTx) {
                self::$db->commit();
            }

            return $count;
        } catch (\Throwable $e) {
            if (!$inTx && self::$db->inTransaction()) {
                self::$db->rollBack();
            }
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Update status reading (misal: setelah cleaning)
    // -----------------------------------------------------------------------
    public static function updateStatus(int $id, string $status, array $extra = []): void
    {
        $sets = ['status = :status'];
        $params = [':id' => $id, ':status' => $status];

        if (isset($extra['is_imputed'])) {
            $sets[] = 'is_imputed = :is_imputed';
            $params[':is_imputed'] = !empty($extra['is_imputed']) ? 'true' : 'false';
        }
        if (isset($extra['is_anomaly'])) {
            $sets[] = 'is_anomaly = :is_anomaly';
            $params[':is_anomaly'] = !empty($extra['is_anomaly']) ? 'true' : 'false';
        }
        if (isset($extra['anomaly_type'])) {
            $sets[] = 'anomaly_type = :anomaly_type';
            $params[':anomaly_type'] = $extra['anomaly_type'];
        }
        if (isset($extra['value'])) {
            $sets[] = 'value = :value';
            $params[':value'] = $extra['value'];
        }

        $sql = "UPDATE sensor_readings SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = self::$db->prepare($sql);
        $stmt->execute($params);
    }

    // -----------------------------------------------------------------------
    // Ambil readings per entity_id dalam range waktu
    // -----------------------------------------------------------------------
    public static function getByEntityId(string $entityId, string $from = null, string $to = null): array
    {
        $sql    = "SELECT * FROM sensor_readings WHERE entity_id = :entity_id";
        $params = [':entity_id' => $entityId];

        if ($from) {
            $sql .= " AND recorded_at >= :from";
            $params[':from'] = $from;
        }
        if ($to) {
            $sql .= " AND recorded_at <= :to";
            $params[':to'] = $to;
        }

        $sql .= " ORDER BY recorded_at ASC";
        $stmt = self::$db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    // Statistik dasar untuk entity_id tertentu (untuk IQR, domain validation)
    // -----------------------------------------------------------------------
    public static function getStats(string $entityId): array
    {
        $stmt = self::$db->prepare("
            SELECT
                COUNT(*) as total,
                COUNT(value) as non_null,
                AVG(value) as avg,
                MIN(value) as min,
                MAX(value) as max,
                STDDEV(value) as stddev
            FROM sensor_readings
            WHERE entity_id = :entity_id
              AND status NOT IN ('missing', 'invalid')
        ");
        $stmt->execute([':entity_id' => $entityId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
