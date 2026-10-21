<?php

/**
 * Model: SensorQuality
 *
 * Menyimpan hasil pengukuran kualitas data per sensor per periode.
 * Empat dimensi: Completeness, Validity, Consistency, Timeliness.
 *
 * Tabel DB: sensor_quality
 */

class SensorQuality
{
    public int    $id;
    public string $entity_id;
    public string $period_start;
    public string $period_end;

    // Completeness — kelengkapan data
    public float $completeness;
    public int   $total_expected;
    public int   $total_missing;

    // Validity — keabsahan nilai
    public float $validity;
    public int   $total_valid;
    public int   $total_invalid;

    // Consistency — konsistensi penamaan/unit/kategori
    public float $consistency;
    public bool  $is_name_consistent;
    public bool  $is_unit_consistent;
    public bool  $is_category_consistent;

    // Timeliness — ketepatan interval pelaporan
    public float $timeliness;
    public int   $expected_reports;
    public int   $on_time_reports;

    public string $assessed_at;

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
            CREATE TABLE IF NOT EXISTS sensor_quality (
                id                      BIGSERIAL PRIMARY KEY,
                entity_id               VARCHAR(255) NOT NULL,
                period_start            TIMESTAMP NOT NULL,
                period_end              TIMESTAMP NOT NULL,

                -- Completeness
                completeness            DOUBLE PRECISION,
                total_expected          INTEGER,
                total_missing           INTEGER,

                -- Validity
                validity                DOUBLE PRECISION,
                total_valid             INTEGER,
                total_invalid           INTEGER,

                -- Consistency
                consistency             DOUBLE PRECISION,
                is_name_consistent      BOOLEAN,
                is_unit_consistent      BOOLEAN,
                is_category_consistent  BOOLEAN,

                -- Timeliness
                timeliness              DOUBLE PRECISION,
                expected_reports        INTEGER,
                on_time_reports         INTEGER,

                assessed_at             TIMESTAMP DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_quality_entity ON sensor_quality(entity_id);
            CREATE INDEX IF NOT EXISTS idx_quality_period ON sensor_quality(period_start, period_end);
        ");
    }

    // -----------------------------------------------------------------------
    // Insert hasil quality assessment
    // -----------------------------------------------------------------------
    public static function insert(array $data): void
    {
        $stmt = self::$db->prepare("
            INSERT INTO sensor_quality
                (entity_id, period_start, period_end,
                 completeness, total_expected, total_missing,
                 validity, total_valid, total_invalid,
                 consistency, is_name_consistent, is_unit_consistent, is_category_consistent,
                 timeliness, expected_reports, on_time_reports)
            VALUES
                (:entity_id, :period_start, :period_end,
                 :completeness, :total_expected, :total_missing,
                 :validity, :total_valid, :total_invalid,
                 :consistency, :is_name_consistent, :is_unit_consistent, :is_category_consistent,
                 :timeliness, :expected_reports, :on_time_reports)
        ");
        $stmt->execute([
            ':entity_id'             => $data['entity_id'],
            ':period_start'          => $data['period_start'],
            ':period_end'            => $data['period_end'],
            ':completeness'          => $data['completeness']          ?? null,
            ':total_expected'        => $data['total_expected']        ?? null,
            ':total_missing'         => $data['total_missing']         ?? null,
            ':validity'              => $data['validity']              ?? null,
            ':total_valid'           => $data['total_valid']           ?? null,
            ':total_invalid'         => $data['total_invalid']         ?? null,
            ':consistency'           => $data['consistency']           ?? null,
            ':is_name_consistent'    => !empty($data['is_name_consistent']) ? 'true' : 'false',
            ':is_unit_consistent'    => !empty($data['is_unit_consistent']) ? 'true' : 'false',
            ':is_category_consistent'=> !empty($data['is_category_consistent']) ? 'true' : 'false',
            ':timeliness'            => $data['timeliness']            ?? null,
            ':expected_reports'      => $data['expected_reports']      ?? null,
            ':on_time_reports'       => $data['on_time_reports']       ?? null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Ambil quality terbaru per entity
    // -----------------------------------------------------------------------
    public static function getLatest(string $entityId): ?array
    {
        $stmt = self::$db->prepare("
            SELECT * FROM sensor_quality
            WHERE entity_id = :entity_id
            ORDER BY assessed_at DESC
            LIMIT 1
        ");
        $stmt->execute([':entity_id' => $entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -----------------------------------------------------------------------
    // Ambil ringkasan quality semua sensor (untuk dashboard)
    // -----------------------------------------------------------------------
    public static function summary(): array
    {
        $stmt = self::$db->query("
            SELECT DISTINCT ON (entity_id)
                entity_id,
                completeness, validity, consistency, timeliness,
                assessed_at
            FROM sensor_quality
            ORDER BY entity_id, assessed_at DESC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
