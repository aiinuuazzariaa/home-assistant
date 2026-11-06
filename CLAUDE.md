# Home Assistant — IoT Sensor Data Quality System

Sistem PHP untuk preprocessing dan pengukuran kualitas data sensor IoT dari Home Assistant.

---

## Arsitektur Sistem

```
Home Assistant REST API
        │
        ▼
scheduler.php  ──────────────────────────────────────────────────────────┐
        │                                                                 │
        ▼                                                                 │
app/Jobs/ProcessSensorData.php  (Orchestrator Pipeline)                  │
        │                                                                 │
        ├─── SensorMappingService     → Identifikasi & Standardisasi     │
        ├─── MissingValueService      → Deteksi Missing + Imputasi       │
        ├─── AnomalyDetectionService  → IQR + Domain + Temporal          │
        ├─── DataCleaningService      → Pipeline Cleaning                │
        └─── DataQualityService       → Completeness/Validity/Consistency/Timeliness
                                                                          │
                                                     PostgreSQL (clean DB)┘
                                                              │
                                                              ▼
                                                    index.php (Front Controller)
                                                              │
                                          ┌───────────────────┼───────────────────┐
                                          ▼                   ▼                   ▼
                                   quality_view.php   mapping_view.php    sensor_view.php
                                   (Quality Dashboard) (Sensor Mapping)   (Data Historis)
```

---

## Struktur Folder

```
app/
├── Models/
│   ├── Sensor.php          → entitas sensor + mapping (tabel: sensor_mapping)
│   ├── SensorReading.php   → data point per sensor  (tabel: sensor_readings)
│   └── SensorQuality.php   → hasil quality assess   (tabel: sensor_quality)
│
├── Services/
│   ├── SensorMappingService.php    → standardisasi entitas, pattern matching
│   ├── MissingValueService.php     → deteksi NULL/unknown/unavailable, timestamp gap, imputasi linear
│   ├── AnomalyDetectionService.php → IQR, domain validation, temporal validation
│   ├── DataCleaningService.php     → pipeline cleaning + persist ke DB
│   └── DataQualityService.php      → Completeness, Validity, Consistency, Timeliness
│
└── Jobs/
    └── ProcessSensorData.php  → orchestrator full pipeline
```

---

## File Utama

| File                | Fungsi                                         |
| ------------------- | ---------------------------------------------- |
| `index.php`         | Front controller — routing `?page=`            |
| `scheduler.php`     | Entry point pipeline (HTTP/CLI/cron)           |
| `api.php`           | Live view data real-time dari HA               |
| `sensor_view.php`   | Viewer data historis (DataTables)              |
| `quality_view.php`  | Dashboard Data Quality Assessment              |
| `mapping_view.php`  | Tabel Sensor Mapping + re-mapping trigger      |
| `table.php`         | Raw DB explorer                                |
| `sidebar.php`       | Navigasi sidebar (membaca sensor_config.php)   |
| `sensor_config.php` | Konfigurasi menu sensor                        |
| `config.php`        | Koneksi PostgreSQL                             |
| `config_api.php`    | URL + token Home Assistant                     |
| `sw.js`             | Service Worker — trigger scheduler pukul 00:01 |

---

## Tabel Database (PostgreSQL)

| Tabel             | Keterangan                                               |
| ----------------- | -------------------------------------------------------- |
| `sensor_mapping`  | Mapping entity_id → standard_name, category, unit        |
| `sensor_readings` | Data point sensor: value, status, is_imputed, is_anomaly |
| `sensor_quality`  | Hasil quality assessment per sensor per periode          |
| `scheduler_logs`  | Log eksekusi pipeline                                    |
| `api_states`      | Snapshot raw states dari HA (existing)                   |
| `api_events`      | Snapshot event log dari HA (existing)                    |

---

## Status Data

Setiap `sensor_readings.status` dapat bernilai:

| Status              | Keterangan                            |
| ------------------- | ------------------------------------- |
| `normal`            | Nilai valid, tidak ada masalah        |
| `missing`           | NULL, empty, unknown, unavailable     |
| `imputed`           | Diisi oleh interpolasi linear         |
| `candidate_anomaly` | Terdeteksi anomali statistik/temporal |
| `invalid`           | Melanggar batas domain sensor         |
| `corrected`         | Sudah dikoreksi secara manual         |

---

## Cara Menjalankan Pipeline

**CLI:**

```bash
php app/Jobs/ProcessSensorData.php
php app/Jobs/ProcessSensorData.php --date=2026-09-25
```

**HTTP:**

```
http://localhost/home-assistant/scheduler.php
http://localhost/home-assistant/scheduler.php?date=2026-09-25
```

---

## Menambah Menu Sensor Baru

Edit `sensor_config.php`, tambahkan entry baru ke array `$sensorMenus`:

```php
'sensor_baru' => [
    'label'   => 'Label Menu',
    'icon'    => 'thermometer',
    'section' => 'Nama Seksi',
    'type'    => 'sensor',
    'filter'  => '%entity_id_pattern%',
    'columns' => ['Waktu', 'Entitas', 'Nilai', 'Atribut'],
],
```

---

## Metrik Kualitas Data

| Dimensi      | Rumus                                                        |
| ------------ | ------------------------------------------------------------ |
| Completeness | `(1 − missing/total) × 100%`                                 |
| Validity     | `(valid/diperiksa) × 100%`                                   |
| Consistency  | `(termapping/total) × 100%` (rata-rata naming+unit+category) |
| Timeliness   | `(laporan_tepat_waktu/laporan_diharapkan) × 100%`            |

---

## AI Agent Instructions

@AGENTS.md

Follow AGENTS.md for development and research rules.
Preserve the documented architecture unless code
inspection demonstrates that changes are necessary.
