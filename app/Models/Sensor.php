<?php

/**
 * Model: Sensor
 *
 * Merepresentasikan entitas sensor dari Home Assistant.
 * Menyimpan entity_id asli beserta informasi standar hasil mapping.
 *
 * Tabel DB: sensor_mapping
 */

class Sensor
{
    public int $id;
    public string $entity_id;       // entity_id asli dari Home Assistant
    public string $standard_name;   // nama standar hasil mapping
    public string $category;        // kategori sensor (Weather, Electrical, dll.)
    public string $measurement_type;// jenis pengukuran (Temperature, Humidity, Voltage, dll.)
    public string $unit;            // satuan standar (°C, %, V, W, dll.)
    public bool   $is_mapped;       // apakah sudah dimapping ke standar

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
            CREATE TABLE IF NOT EXISTS sensor_mapping (
                id              BIGSERIAL PRIMARY KEY,
                entity_id       VARCHAR(255) NOT NULL UNIQUE,
                standard_name   VARCHAR(255) NOT NULL,
                category        VARCHAR(100),
                measurement_type VARCHAR(100),
                unit            VARCHAR(50),
                is_mapped       BOOLEAN DEFAULT FALSE,
                created_at      TIMESTAMP DEFAULT NOW(),
                updated_at      TIMESTAMP DEFAULT NOW()
            );
        ");
    }

    // -----------------------------------------------------------------------
    // Ambil semua sensor
    // -----------------------------------------------------------------------
    public static function all(): array
    {
        $stmt = self::$db->query("SELECT * FROM sensor_mapping ORDER BY category, standard_name");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    // Cari berdasarkan entity_id
    // -----------------------------------------------------------------------
    public static function findByEntityId(string $entityId): ?array
    {
        $stmt = self::$db->prepare("SELECT * FROM sensor_mapping WHERE entity_id = ?");
        $stmt->execute([$entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -----------------------------------------------------------------------
    // Upsert — insert atau update mapping sensor
    // -----------------------------------------------------------------------
    public static function upsert(array $data): void
    {
        $stmt = self::$db->prepare("
            INSERT INTO sensor_mapping (entity_id, standard_name, category, measurement_type, unit, is_mapped, updated_at)
            VALUES (:entity_id, :standard_name, :category, :measurement_type, :unit, :is_mapped, NOW())
            ON CONFLICT (entity_id) DO UPDATE
                SET standard_name    = EXCLUDED.standard_name,
                    category         = EXCLUDED.category,
                    measurement_type = EXCLUDED.measurement_type,
                    unit             = EXCLUDED.unit,
                    is_mapped        = EXCLUDED.is_mapped,
                    updated_at       = NOW()
        ");
        $stmt->execute([
            ':entity_id'       => $data['entity_id'],
            ':standard_name'   => $data['standard_name'],
            ':category'        => $data['category']         ?? null,
            ':measurement_type'=> $data['measurement_type'] ?? null,
            ':unit'            => $data['unit']             ?? null,
            ':is_mapped'       => !empty($data['is_mapped']) ? 'true' : 'false',
        ]);
    }

    // -----------------------------------------------------------------------
    // Hitung jumlah sensor per kategori
    // -----------------------------------------------------------------------
    public static function countByCategory(): array
    {
        $stmt = self::$db->query("
            SELECT category, COUNT(*) as total
            FROM sensor_mapping
            GROUP BY category
            ORDER BY total DESC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
