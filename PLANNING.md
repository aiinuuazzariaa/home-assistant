# PLANNING.md — Fix Dedup Logic Scheduler

## Masalah yang Ditemukan

Saat scheduler berjalan berulang kali, ditemukan dua bug berbeda:

1. **Tabel flat (`api_states`, `api_history`, `api_logbook`) tidak bertambah data** setelah run pertama.
2. **Tabel relasional `states`** (yang dibaca tabel Akar di UI) **terus bertambah duplikat** setiap run.

---

## Root Cause

### Root Cause 1 — `sync_to_db()`: dedup terlalu ketat

Fungsi ini mendeteksi kolom timestamp (`last_updated`, `last_changed`, dll.) dan mengecek:
> "Jika sudah ada row dengan `entity_id + last_updated` yang sama → skip insert."

**Masalah:** `api/states` hanya mengembalikan state terkini satu entity. Jika state tidak berubah di HA sejak run sebelumnya, `last_updated` tidak berubah → semua row diskip → tabel tidak bertambah meski sudah hari baru.

### Root Cause 2 — `sync_relational_states()`: tidak ada dedup

Fungsi ini selalu INSERT ke tabel `states` dan `state_attributes` tanpa mengecek apakah data sudah ada → tabel terus bertambah duplikat setiap run scheduler.

---

## Rencana Perbaikan

### Fix 1 — `sync_to_db()`: Ubah dedup menjadi "1 snapshot per hari per entity"

**Lokasi:** di dalam loop insert di fungsi `sync_to_db()` (sekitar baris 329–351 di `scheduler.php`)

**Perubahan:** tambahkan filter tanggal `fetched_at` ke dedup query:

```php
$today_date = date('Y-m-d');

// Jika tabel punya entity_id:
$stmtCheck = $db->prepare(
    'SELECT COUNT(*) FROM "table"
     WHERE "entity_id" = ?
     AND "ts_col" = ?
     AND CAST("fetched_at" AS DATE) = ?'
);
$stmtCheck->execute([$insertData['entity_id'], $ts_val, $today_date]);

// Jika tabel tidak punya entity_id:
$stmtCheck = $db->prepare(
    'SELECT COUNT(*) FROM "table"
     WHERE "ts_col" = ?
     AND CAST("fetched_at" AS DATE) = ?'
);
$stmtCheck->execute([$ts_val, $today_date]);
```

**Efek yang diharapkan:**
- Setiap hari → 1 snapshot baru per entity bisa masuk.
- Run kedua di hari yang sama tetap diskip (tidak duplikat).
- Hari berikutnya → snapshot baru bisa masuk lagi.

---

### Fix 2 — `sync_relational_states()`: Tambah dedup sebelum INSERT ke `states`

**Lokasi:** di dalam loop `foreach ($statesData as $item)` di fungsi `sync_relational_states()` (sekitar baris 408–444 di `scheduler.php`)

**Perubahan A — dedup INSERT ke `states`:**
```php
// Cek duplikat sebelum insert
$stmtCheck = $db->prepare(
    "SELECT COUNT(*) FROM states WHERE metadata_id = ? AND last_updated_ts = ?"
);
$stmtCheck->execute([$metadata_id, $last_updated_ts]);
if (intval($stmtCheck->fetchColumn()) > 0) {
    continue; // sudah ada, skip
}
```

**Perubahan B — reuse `attributes_id` jika `shared_attrs` identik (hemat storage):**
```php
// Reuse attributes yang sudah ada jika identical
$stmtAttr = $db->prepare(
    "SELECT sa.attributes_id FROM state_attributes sa
     JOIN states st ON st.attributes_id = sa.attributes_id
     WHERE st.metadata_id = ? AND sa.shared_attrs = ?
     LIMIT 1"
);
$stmtAttr->execute([$metadata_id, $shared_attrs]);
$existing_attr_id = $stmtAttr->fetchColumn();

if ($existing_attr_id) {
    $attributes_id = $existing_attr_id;
} else {
    $db->prepare("INSERT INTO state_attributes (shared_attrs) VALUES (?)")
       ->execute([$shared_attrs]);
    $attributes_id = $db->lastInsertId();
}
```

---

## File yang Akan Dimodifikasi

- `scheduler.php`
  - Fungsi `sync_to_db()` — bagian dedup sebelum INSERT
  - Fungsi `sync_relational_states()` — tambah dedup + reuse attributes

---

## Cara Verifikasi

1. Jalankan `scheduler.php` dua kali pada hari yang sama.
2. Run kedua harus melaporkan `0 baris baru` di `scheduler_logs` untuk `api_states`.
3. Cek tabel `states` di PostgreSQL: tidak ada duplikat `metadata_id + last_updated_ts`.
4. Jalankan scheduler keesokan harinya → harus ada baris baru (1 per entity).
5. Cek tabel Akar di UI — baris tidak bertambah duplikat setelah run kedua pada hari yang sama.
