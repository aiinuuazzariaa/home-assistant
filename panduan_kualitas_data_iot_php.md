# Panduan Implementasi Peningkatan Kualitas Data Sensor IoT dengan PHP/Laravel

## 1. Tujuan Sistem

Sistem bertujuan mengolah data sensor IoT dari Home Assistant agar lebih terstruktur dan layak dianalisis. Tiga permasalahan utama yang ditangani adalah:

1. **Missing value** — data sensor hilang atau tidak tersedia.
2. **Anomali** — nilai sensor tidak wajar atau mencurigakan.
3. **Inkonsistensi entitas/sensor** — penamaan, penggolongan, metadata, atau satuan sensor tidak seragam.

Setelah preprocessing dan data cleaning, kualitas data diukur menggunakan **Completeness, Validity, Consistency, dan Timeliness**.

---

## 2. Istilah Penting

### Entity / Entitas

Entitas adalah objek pada Home Assistant yang merepresentasikan keadaan atau hasil pembacaan perangkat.

Contoh:

```text
sensor.weather_temperature
sensor.weather_humidity
sensor.sonoff_voltage_1
```

### Preprocessing

Preprocessing adalah proses menyiapkan data sebelum dianalisis, misalnya mengidentifikasi sensor, menyeragamkan nama dan satuan, mendeteksi missing value, dan mendeteksi anomali.

### Data Cleaning

Data cleaning adalah proses memperbaiki masalah pada data. Cleaning tidak selalu berarti menghapus data; data dapat ditandai, dikoreksi, distandardisasi, atau diimputasi.

### Mapping

Mapping adalah proses mencocokkan entitas asli dengan identitas atau kategori standar.

Contoh:

```text
sensor.weather_temp
        ↓
standard_name = weather_temperature
category      = weather
measurement   = temperature
unit          = °C
```

### Imputasi

Imputasi adalah proses mengisi nilai yang hilang menggunakan nilai perkiraan berdasarkan metode tertentu.

### Outlier dan Anomali

**Outlier** adalah nilai yang secara statistik jauh dari sebagian besar data.

**Anomali** adalah data yang dianggap tidak wajar berdasarkan statistik, pola waktu, atau aturan/karakteristik sensor.

Outlier belum tentu merupakan kesalahan sensor.

---

## 3. Alur Sistem

```text
HOME ASSISTANT
      │
      ▼
RAW SENSOR DATA
      │
      ▼
IDENTIFIKASI SENSOR
      │
      ▼
STANDARDISASI ENTITAS
├── Penamaan
├── Kategori
├── Jenis pengukuran
└── Satuan
      │
      ▼
DETEKSI MISSING VALUE
├── NULL
├── empty
├── unknown
├── unavailable
└── timestamp gap
      │
      ▼
DETEKSI ANOMALI
├── Statistical validation
├── Domain validation
└── Temporal validation
      │
      ▼
DATA CLEANING
├── Standardisasi
├── Imputasi jika diperlukan
└── Penanganan anomali
      │
      ▼
DATA QUALITY ASSESSMENT
├── Completeness
├── Validity
├── Consistency
└── Timeliness
      │
      ▼
DATASET BERSIH / DATABASE
      │
      ▼
DASHBOARD / ANALISIS
```

---

# 4. Masalah 1 — Inkonsistensi Penamaan dan Penggolongan Sensor

## Permasalahan

Sensor yang memiliki fungsi serupa dapat mempunyai pola nama yang berbeda.

Contoh:

```text
sensor.temperature
sensor.weather_temp
sensor.outdoor_temperature
sensor.temp_1
```

Bagi manusia nama tersebut masih dapat dipahami, tetapi sistem tidak otomatis mengetahui bahwa beberapa entitas tersebut sama-sama berkaitan dengan temperatur.

## Solusi: Sensor Mapping / Sensor Dictionary

Buat master sensor yang mempertahankan nama asli tetapi menambahkan informasi standar.

Contoh struktur:

| entity_id_original | standard_name | category | measurement_type | unit_standard |
|---|---|---|---|---|
| sensor.weather_temp | weather_temperature | Weather | Temperature | °C |
| sensor.outdoor_temperature | outdoor_temperature | Weather | Temperature | °C |
| sensor.sonoff_voltage_1 | voltage_1 | Electrical | Voltage | V |

Nama asli sebaiknya tetap disimpan agar perubahan data dapat dilacak kembali ke sumbernya (**traceability**).

Contoh tabel database:

```sql
CREATE TABLE sensor_mapping (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    entity_id VARCHAR(255) NOT NULL,
    standard_name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    measurement_type VARCHAR(100),
    unit VARCHAR(50)
);
```

---

# 5. Masalah 2 — Missing Value

## Pengertian

Missing value adalah nilai yang seharusnya tersedia tetapi tidak dapat digunakan.

Dalam Home Assistant missing value tidak hanya berupa `NULL`.

Contoh:

```text
NULL
""
unknown
unavailable
```

Contoh data:

```text
08:00 → 27.2 °C
08:05 → 27.4 °C
08:10 → unavailable
08:15 → 27.8 °C
```

Data pukul 08:10 dianggap tidak tersedia.

## Deteksi Missing Value dengan PHP

```php
function isMissingValue($value): bool
{
    return $value === null
        || trim((string) $value) === ''
        || strtolower((string) $value) === 'unknown'
        || strtolower((string) $value) === 'unavailable';
}
```

## Deteksi Tipe Data Numerik (Bulat, Desimal, Persentase)

Selain ketersediaan nilai, validasi format memeriksa tipe angka yang dihasilkan sensor:

```php
function detectNumericType(mixed $value): string
{
    $valStr = trim((string) $value);

    // 1. Persentase: memiliki simbol % atau format persentase
    if (str_ends_with($valStr, '%')) {
        $clean = trim(substr($valStr, 0, -1));
        if (is_numeric($clean)) {
            return 'percentage';
        }
    }

    if (!is_numeric($valStr)) {
        return 'non_numeric';
    }

    $num = (float) $valStr;

    // 2. Bilangan bulat: tidak memiliki pecahan
    if (floor($num) == $num && !str_contains($valStr, '.')) {
        return 'integer';
    }

    // 3. Bilangan desimal: memiliki pecahan / floating point
    return 'decimal';
}
```

## Timestamp Gap

Missing data juga dapat diketahui dari jarak waktu antarpembacaan.

Misalnya sensor seharusnya mengirim setiap 5 menit:

```text
08:00 → ada
08:05 → ada
08:10 → tidak ada
08:15 → ada
```

Tidak terdapat baris `NULL` untuk pukul 08:10, tetapi secara waktu terdapat data yang hilang.

Secara sederhana:

```php
$gap = $currentTimestamp - $previousTimestamp;

if ($gap > $expectedInterval) {
    // Terdapat timestamp gap
}
```

---

# 6. Penanganan Missing Value dan Validasi Tipe Data

## 6.1 Interpolasi Nilai Hilang

Salah satu metode untuk gap pendek adalah **interpolasi**.

Contoh:

```text
08:00 → 20
08:05 → ???
08:10 → 30
```

Perkiraan sederhana:

```text
(20 + 30) / 2 = 25
```

Hasil:

```text
08:00 → 20
08:05 → 25  (hasil imputasi)
08:10 → 30
```

Untuk interval waktu yang tidak sama, gunakan interpolasi linear berbasis waktu:

```text
x(t) = x1 + ((t - t1) / (t2 - t1)) × (x2 - x1)
```

Data hasil imputasi sebaiknya diberi penanda:

```text
is_imputed = true
```

Tujuannya agar nilai asli sensor dan nilai hasil perkiraan tetap dapat dibedakan.

Jangan memaksakan imputasi pada gap yang terlalu panjang tanpa dasar yang kuat.

---

## 6.2 Penanganan 3 Tipe Bilangan (Bulat, Desimal, Persentase)

Ketika data tidak valid, berformat string kotor, atau diimputasi, terapkan penanganan spesifik per tipe:

### 1. Bilangan Bulat (Integer)
- **Contoh**: Counter detak, status kode, jumlah orang/kendaraan.
- **Masalah**: Hasil imputasi menghasilkan angka desimal (misal `2.5`), atau string bertipe float (`"3.0"`).
- **Penanganan**:
  - **Sanitasi**: Cast ke integer menggunakan pembulatan `(int) round((float) $val)`.
  - **Imputasi**: Bulatkan hasil interpolasi dengan `round()` atau gunakan metode *Nearest Neighbor* / *Forward Fill* agar nilai tetap diskrit bulat.

```php
function handleInteger(mixed $value): int
{
    return (int) round((float) $value);
}
```

### 2. Bilangan Desimal (Float)
- **Contoh**: Suhu (°C), tegangan (V), arus (A), daya (W).
- **Masalah**: Pemisah koma bukan titik (`27,5`), satuan teks menempel (`"27.5 °C"`), presisi desimal berlebih.
- **Penanganan**:
  - **Sanitasi**: Ganti koma dengan titik `str_replace(',', '.', $val)` dan buang karakter selain angka, titik, dan minus.
  - **Presisi**: Batasi desimal sesuai resolusi sensor via `round((float) $val, 2)`.
  - **Imputasi**: Interpolasi linear berbasis waktu langsung menghasilkan nilai float.

```php
function handleDecimal(mixed $value, int $precision = 2): float
{
    $clean = preg_replace('/[^0-9.-]/', '', str_replace(',', '.', (string) $value));
    return round((float) $clean, $precision);
}
```

### 3. Persentase (Percentage)
- **Contoh**: Kelembapan (%RH), baterai (%), duty cycle (%).
- **Masalah**: Simbol `%` menempel (`"78%"`), skala fraksi `0.0–1.0` bercampur dengan `0–100%`, nilai keluar batas (`< 0%` atau `> 100%`).
- **Penanganan**:
  - **Pembersihan**: Buang simbol `%`.
  - **Normalisasi Skala**: Jika nilai berada di rentang `0.0 <= x <= 1.0` (skala rasio), kalikan `100`.
  - **Clamping (Batas Domain)**: Batasi nilai agar selalu berada di rentang `0` sampai `100` (`min(100.0, max(0.0, $val))`).
  - **Imputasi**: Hasil interpolasi wajib dipotong (clamped) agar tidak menembus batas `[0, 100]`.

```php
function handlePercentage(mixed $value): float
{
    $clean = preg_replace('/[^0-9.-]/', '', str_replace(',', '.', (string) $value));
    $num   = (float) $clean;

    // Normalisasi skala 0.0 - 1.0 ke 0 - 100
    if ($num >= 0.0 && $num <= 1.0 && str_contains((string) $value, '.')) {
        $num *= 100;
    }

    // Clamping domain 0% - 100%
    return round(min(100.0, max(0.0, $num)), 2);
}
```

---

# 7. Masalah 3 — Anomali Sensor

## Pengertian

Anomali adalah nilai atau pola yang tidak wajar dibandingkan keadaan yang diharapkan.

Contoh:

```text
27.2
27.4
150.0  ← mencurigakan
27.5
```

Namun nilai yang berbeda jauh belum tentu salah.

Contoh sensor daya:

```text
10 W
11 W
500 W
480 W
510 W
12 W
```

Nilai 500 W mungkin muncul karena perangkat sedang aktif. Karena itu hasil metode statistik sebaiknya disebut **kandidat anomali** terlebih dahulu.

---

# 8. Deteksi Anomali

Disarankan menggunakan kombinasi tiga pendekatan.

## 8.1 Statistical Validation

Statistical validation mencari nilai yang berbeda jauh dari distribusi data.

Salah satu metode yang dapat digunakan adalah **IQR (Interquartile Range)**.

```text
IQR = Q3 - Q1
```

Batas bawah:

```text
Lower = Q1 - 1.5 × IQR
```

Batas atas:

```text
Upper = Q3 + 1.5 × IQR
```

Nilai di luar batas tersebut menjadi kandidat outlier.

```text
value < Lower
atau
value > Upper
```

## 8.2 Domain Validation

Domain validation berarti memeriksa nilai berdasarkan aturan jenis sensor.

Contoh kelembapan relatif:

```text
0% <= humidity <= 100%
```

Contoh PHP:

```php
if ($sensorType === 'humidity') {
    if ($value < 0 || $value > 100) {
        $status = 'invalid';
    }
}
```

Batas domain harus ditentukan berdasarkan karakteristik, spesifikasi, dan satuan sensor yang sebenarnya.

## 8.3 Temporal Validation

Temporal berarti berhubungan dengan **waktu**.

Metode ini memeriksa perubahan nilai dari satu waktu ke waktu berikutnya.

Contoh:

```text
12:00 → 229.5 V
12:01 → 230.1 V
12:02 → 5000 V   ← sangat mencurigakan
12:03 → 229.8 V
```

Perubahan mendadak yang sangat besar dapat ditandai sebagai kandidat anomali untuk diperiksa lebih lanjut.

---

# 9. Status Data

Daripada langsung menghapus data bermasalah, sistem dapat memberi status.

Contoh:

```text
normal
missing
candidate_anomaly
invalid
imputed
corrected
```

Dengan pendekatan ini data asli tetap dapat dilacak dan keputusan cleaning lebih transparan.

---

# 10. Pengukuran Kualitas Data

Setelah preprocessing, kualitas data dapat diukur menggunakan empat dimensi utama:

```text
Completeness
Validity
Consistency
Timeliness
```

## 10.1 Completeness — Kelengkapan

Pertanyaan yang dijawab:

> Apakah data yang dibutuhkan tersedia?

Rumus sederhana:

```text
Completeness = (jumlah data tersedia / jumlah data yang diharapkan) × 100%
```

Jika menggunakan jumlah data tercatat:

```text
Completeness = (1 - jumlah missing / jumlah total) × 100%
```

Contoh:

```text
Total   = 1000
Missing = 50
Valid   = 950
```

```text
Completeness = (950 / 1000) × 100% = 95%
```

---

## 10.2 Validity — Keabsahan

Pertanyaan yang dijawab:

> Apakah nilai memenuhi aturan yang berlaku untuk sensor tersebut?

Rumus:

```text
Validity = (jumlah nilai valid / jumlah data yang diperiksa) × 100%
```

Contoh kelembapan:

```text
50%  → valid
75%  → valid
150% → invalid
```

---

## 10.3 Consistency — Konsistensi

Pertanyaan yang dijawab:

> Apakah data memiliki penamaan, klasifikasi, metadata, dan satuan yang konsisten?

Consistency dapat diperiksa melalui beberapa aspek:

```text
Naming Consistency
Classification Consistency
Unit Consistency
```

Contoh rumus naming consistency:

```text
Naming Consistency =
(jumlah entitas sesuai standar / jumlah seluruh entitas) × 100%
```

Contoh:

```text
Total sensor              = 41
Sensor sesuai standar     = 30
```

```text
Naming Consistency = (30 / 41) × 100% = 73.17%
```

Setelah standardisasi, nilai consistency dapat dihitung kembali untuk membandingkan kondisi sebelum dan sesudah preprocessing.

---

## 10.4 Timeliness — Ketepatan Waktu

Pertanyaan yang dijawab:

> Apakah sensor melaporkan data sesuai interval waktu yang diharapkan?

Misalnya sensor seharusnya mengirim data setiap 5 menit:

```text
08:00 → ada
08:05 → ada
08:10 → ada
08:15 → ada
```

Jika terjadi:

```text
08:00 → ada
08:05 → ada
08:25 → ada
```

terdapat gap waktu antara 08:05 dan 08:25.

Selisih waktu dapat dihitung:

```text
Δt = timestamp sekarang - timestamp sebelumnya
```

Contoh metrik:

```text
Timeliness =
(jumlah laporan yang memenuhi interval / jumlah laporan yang diharapkan) × 100%
```

---

# 11. Evaluasi Sebelum dan Sesudah Cleaning

Tujuan sistem bukan hanya menghasilkan data yang terlihat lebih rapi. Peningkatan kualitas harus dapat diukur.

Contoh tabel evaluasi:

| Indikator | Sebelum Cleaning | Sesudah Cleaning |
|---|---:|---:|
| Completeness | 96.61% | ...% |
| Validity | ...% | ...% |
| Consistency | ...% | ...% |
| Timeliness | ...% | ...% |

Hindari memaksakan semua indikator menjadi 100%. Misalnya mengisi semua missing value dapat meningkatkan completeness secara angka, tetapi nilai hasil imputasi belum tentu menggambarkan kondisi sensor sebenarnya.

---

# 12. Implementasi dengan PHP/Laravel

Semua proses utama dapat diterapkan menggunakan PHP/Laravel, antara lain:

- membaca data Home Assistant;
- sensor mapping;
- standardisasi entitas;
- mendeteksi `NULL`, `unknown`, dan `unavailable`;
- mendeteksi timestamp gap;
- interpolasi sederhana;
- menghitung IQR;
- domain validation;
- temporal validation;
- menghitung metrik kualitas data;
- menyimpan hasil preprocessing; dan
- menampilkan hasil pada dashboard.

Contoh struktur Laravel:

```text
app/
├── Models/
│   ├── Sensor.php
│   ├── SensorReading.php
│   └── SensorQuality.php
│
├── Services/
│   ├── SensorMappingService.php
│   ├── MissingValueService.php
│   ├── AnomalyDetectionService.php
│   ├── DataCleaningService.php
│   └── DataQualityService.php
│
└── Jobs/
    └── ProcessSensorData.php
```

### Service

Service adalah bagian program yang memiliki tanggung jawab khusus.

Contoh:

```text
SensorMappingService
```

bertugas melakukan standardisasi/mapping sensor.

```text
MissingValueService
```

bertugas mendeteksi dan menangani missing value.

```text
AnomalyDetectionService
```

bertugas mendeteksi kandidat anomali.

```text
DataQualityService
```

bertugas menghitung completeness, validity, consistency, dan timeliness.

### Job

Job adalah pekerjaan yang dapat diproses oleh aplikasi, termasuk melalui queue/background worker, sehingga pengolahan data dalam jumlah besar tidak harus dilakukan langsung dalam request halaman pengguna.

---

# 13. Arsitektur Sederhana Sistem

```text
Home Assistant
      │
      ▼
Database / Sumber Data
      │
      ▼
PHP / Laravel
      │
      ▼
Sensor Mapping & Standardization
      │
      ▼
Missing Value Detection
      │
      ▼
Anomaly Detection
      │
      ▼
Data Cleaning
      │
      ▼
Data Quality Assessment
      │
      ▼
Clean Database
      │
      ▼
Dashboard / Analisis
```

---

# 14. Ringkasan Metode

| Permasalahan | Deteksi | Penanganan | Evaluasi |
|---|---|---|---|
| Inkonsistensi entitas | Cek nama, kategori, measurement type, unit | Sensor mapping dan standardisasi | Consistency |
| Missing value | NULL, empty, unknown, unavailable, timestamp gap | Imputasi/interpolasi jika sesuai, atau tandai missing | Completeness dan Timeliness |
| Anomali | IQR + domain validation + temporal validation | Validasi, tandai, koreksi bila memiliki dasar | Validity/Anomaly Rate |

---

# 15. Kesimpulan

Sistem PHP/Laravel dapat digunakan untuk menangani ketiga masalah kualitas data sensor IoT pada Home Assistant.

Alur utamanya adalah:

```text
Raw IoT Data
    ↓
Identifikasi dan Standardisasi Sensor
    ↓
Missing Value Detection
    ↓
Anomaly Detection
    ↓
Data Cleaning
    ↓
Data Quality Assessment
    ↓
Dataset yang lebih layak dianalisis
```

Keberhasilan proses tidak hanya dilihat dari apakah data sudah "bersih", tetapi diukur menggunakan **Completeness, Validity, Consistency, dan Timeliness**, serta dibandingkan antara kondisi sebelum dan sesudah preprocessing.
