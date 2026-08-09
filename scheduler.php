<?php
/** scheduler home assistant */

set_time_limit(0);
ignore_user_abort(true);

require 'config.php';
require 'config_api.php';

// set zona waktu lokal
date_default_timezone_set('Asia/Jakarta');

// simpan log proses
function log_scheduler($db, $status, $details, $endpoint = null) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS scheduler_logs (
            id SERIAL PRIMARY KEY,
            run_time VARCHAR(255),
            status VARCHAR(255),
            endpoint VARCHAR(255),
            details TEXT
        )");
        $stmt = $db->prepare("INSERT INTO scheduler_logs (run_time, status, endpoint, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([date('Y-m-d H:i:s'), $status, $endpoint, $details]);
    } catch (Exception $e) {
        // fallback ke error log php jika db terkunci
        error_log("Scheduler logging failed: " . $e->getMessage());
    }
}

// cek akses web
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($client_ip !== '127.0.0.1' && $client_ip !== '::1') {
        // batasi akses selain localhost
    }
}

try {
    // ambil data dari rest api home assistant
    function fetch_ha_api($ha_url, $ha_token, $endpoint, $is_binary = false) {
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $ha_token\r\n" .
                            "Content-Type: application/json\r\n",
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $url = rtrim($ha_url, '/') . '/' . ltrim($endpoint, '/');
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception("HTTP request failed for: $endpoint");
        }

        // cek status http
        $status_line = $http_response_header[0] ?? '';
        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
        $status_code = isset($match[1]) ? intval($match[1]) : 0;

        if ($status_code >= 400) {
            throw new Exception("API returned status code $status_code for $endpoint: " . substr($response, 0, 200));
        }

        if ($is_binary) {
            return $response;
        }

        return json_decode($response, true);
    }

    // build tabel sqlite dari data
    function sync_to_db($db, $tableName, $data, $is_binary = false) {
        if ($data === null || $data === false) {
            return;
        }

        // struktur tabel
        $columns = [
            'id' => 'SERIAL PRIMARY KEY'
        ];

        $rows = [];

        if ($is_binary) {
            $columns['entity_id'] = 'VARCHAR(255)';
            $columns['image_bytes'] = 'BYTEA';
            $columns['fetched_at'] = 'VARCHAR(255)';
            $rows[] = $data;
        } elseif (!is_array($data)) {
            // log teks
            $columns['error_log'] = 'TEXT';
            $columns['fetched_at'] = 'VARCHAR(255)';
            $rows[] = ['error_log' => strval($data)];
        } else {
            // array list atau object
            $is_sequential = (array_keys($data) === range(0, count($data) - 1));

            if (!$is_sequential) {
                $rows[] = $data;
            } else {
                if (!empty($data) && is_array($data[0])) {
                    // nested array
                    $is_nested = (array_keys($data[0]) === range(0, count($data[0]) - 1));
                    if ($is_nested) {
                        foreach ($data as $subArray) {
                            if (is_array($subArray)) {
                                foreach ($subArray as $item) {
                                    $rows[] = $item;
                                }
                            }
                        }
                    } else {
                        $rows = $data;
                    }
                } else {
                    // array nilai
                    foreach ($data as $item) {
                        $rows[] = ['component' => $item];
                    }
                }
            }

            // rapikan kalender
            if ($tableName === 'api_calendar_events') {
                $flattenedRows = [];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $newRow = $row;
                        if (isset($row['start']) && is_array($row['start'])) {
                            $newRow['start_date'] = $row['start']['date'] ?? null;
                            $newRow['start_datetime'] = $row['start']['dateTime'] ?? null;
                            unset($newRow['start']);
                        }
                        if (isset($row['end']) && is_array($row['end'])) {
                            $newRow['end_date'] = $row['end']['date'] ?? null;
                            $newRow['end_datetime'] = $row['end']['dateTime'] ?? null;
                            unset($newRow['end']);
                        }
                        $flattenedRows[] = $newRow;
                    } else {
                        $flattenedRows[] = $row;
                    }
                }
                $rows = $flattenedRows;
            }

            // schema dari data
            $allKeys = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    foreach (array_keys($row) as $k) {
                        $allKeys[$k] = true;
                    }
                }
            }

            foreach (array_keys($allKeys) as $k) {
                $colName = preg_replace('/[^a-zA-Z0-9_]/', '_', $k);
                if (empty($colName)) {
                    $colName = 'col_' . md5($k);
                }

                $type = 'TEXT';
                foreach ($rows as $row) {
                    if (isset($row[$k]) && $row[$k] !== null) {
                        $val = $row[$k];
                        if (is_bool($val)) {
                            $type = 'INTEGER';
                        } elseif (is_int($val)) {
                            if ($type !== 'DOUBLE PRECISION') {
                                $type = 'INTEGER';
                            }
                        } elseif (is_float($val)) {
                            $type = 'DOUBLE PRECISION';
                        }
                    }
                }
                $columns[$colName] = $type;
            }

            $columns['fetched_at'] = 'VARCHAR(255)';
        }

        // create table
        $colDefs = [];
        foreach ($columns as $colName => $def) {
            $colDefs[] = '"' . str_replace('"', '""', $colName) . '" ' . $def;
        }
        $createSql = 'CREATE TABLE IF NOT EXISTS "' . str_replace('"', '""', $tableName) . '" (' . implode(', ', $colDefs) . ')';
        $db->exec($createSql);

        // ambil kolom aktif dengan standard SQL (PostgreSQL compatible)
        $stmtCols = $db->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_name = :table AND table_schema = 'public'
        ");
        $stmtCols->execute([':table' => $tableName]);
        $dbCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

        // prepare insert
        $preparedRows = [];
        foreach ($rows as $row) {
            $insertData = [];
            if ($is_binary) {
                $insertData['entity_id'] = $row['entity_id'] ?? '';
                $insertData['image_bytes'] = $row['image_bytes'] ?? null;
            } else {
                foreach ($dbCols as $col) {
                    if ($col === 'id' || $col === 'fetched_at') continue;

                    $val = null;
                    if (isset($row[$col])) {
                        $val = $row[$col];
                    } else {
                        // cari key yang cocok
                        foreach ($row as $origK => $origV) {
                            $mappedK = preg_replace('/[^a-zA-Z0-9_]/', '_', $origK);
                            if ($mappedK === $col) {
                                $val = $origV;
                                break;
                            }
                        }
                    }

                    if (is_array($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                    } elseif (is_bool($val)) {
                        $val = $val ? 1 : 0;
                    }
                    $insertData[$col] = $val;
                }
            }
            $preparedRows[] = $insertData;
        }

        // deteksi timestamp
        $TIMESTAMP_PRIORITY = [
            'last_updated',
            'last_changed',
            'last_reported',
            'when',
            'timestamp',
            'time',
            'start',
            'created_at',
            'created',
            'date',
            'end',
        ];

        $timestamp_col = null;
        $has_entity_id = false;

        if (!empty($preparedRows)) {
            $firstRow = $preparedRows[0];
            $rowKeys   = array_map('strtolower', array_keys($firstRow));

            foreach ($TIMESTAMP_PRIORITY as $candidate) {
                if (in_array(strtolower($candidate), $rowKeys)) {
                    foreach (array_keys($firstRow) as $k) {
                        if (strtolower($k) === strtolower($candidate)) {
                            $timestamp_col = $k;
                            break;
                        }
                    }
                    break;
                }
            }

            $has_entity_id = array_key_exists('entity_id', $firstRow);
        }

        $has_timestamp = ($timestamp_col !== null);

        // tabel tanpa timestamp: overwrite jika data sama
        if (!$has_timestamp) {
            $should_overwrite = false;
            try {
                $stmt = $db->query('SELECT * FROM "' . str_replace('"', '""', $tableName) . '"');
                $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $dbPreparedRows = [];
                foreach ($existingRows as $exRow) {
                    $cleaned = [];
                    foreach ($exRow as $k => $v) {
                        if ($k === 'id' || $k === 'fetched_at') continue;
                        $cleaned[$k] = $v;
                    }
                    $dbPreparedRows[] = $cleaned;
                }

                if (count($dbPreparedRows) === count($preparedRows)) {
                    $normalizeFn = function($r) {
                        $normalized = [];
                        foreach ($r as $k => $v) {
                            $normalized[$k] = ($v === null) ? null : strval($v);
                        }
                        ksort($normalized);
                        return json_encode($normalized);
                    };
                    $strDb  = array_map($normalizeFn, $dbPreparedRows);
                    sort($strDb);
                    $strNew = array_map($normalizeFn, $preparedRows);
                    sort($strNew);
                    if ($strDb === $strNew) {
                        $should_overwrite = true;
                    }
                }
            } catch (Exception $e) {}

            if ($should_overwrite) {
                $db->exec('DELETE FROM "' . str_replace('"', '""', $tableName) . '"');
            }
        }

        // insert rows
        // tabel bertimestamp: skip jika data sudah ada
        $fetched_at = date('Y-m-d H:i:s');

        foreach ($preparedRows as $insertData) {
            if (empty($insertData)) continue;

            // dedup tabel bertimestamp
            if ($has_timestamp) {
                $ts_val = $insertData[$timestamp_col] ?? null;

                if ($ts_val !== null) {
                    // where dinamis
                    if ($has_entity_id && isset($insertData['entity_id'])) {
                        $stmtCheck = $db->prepare(
                            'SELECT COUNT(*) FROM "' . str_replace('"', '""', $tableName) . '"'
                            . ' WHERE "entity_id" = ? AND "' . str_replace('"', '""', $timestamp_col) . '" = ?'
                        );
                        $stmtCheck->execute([$insertData['entity_id'], $ts_val]);
                    } else {
                        $stmtCheck = $db->prepare(
                            'SELECT COUNT(*) FROM "' . str_replace('"', '""', $tableName) . '"'
                            . ' WHERE "' . str_replace('"', '""', $timestamp_col) . '" = ?'
                        );
                        $stmtCheck->execute([$ts_val]);
                    }

                    if (intval($stmtCheck->fetchColumn()) > 0) {
                        continue; // sudah ada
                    }
                }
            }

            $insertData['fetched_at'] = $fetched_at;
            $fields       = implode(', ', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', array_keys($insertData)));
            $placeholders = implode(', ', array_map(fn($c) => ':' . $c, array_keys($insertData)));

            $stmt = $db->prepare('INSERT INTO "' . str_replace('"', '""', $tableName) . '" (' . $fields . ') VALUES (' . $placeholders . ')');
            $stmt->execute($insertData);
        }
    }

    // hitung row baru
    function sync_and_count($db, $tableName, $data, $is_binary = false) {
        $before = 0;
        try {
            $before = intval($db->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $tableName) . '"')->fetchColumn());
        } catch (Exception $e) {}

        sync_to_db($db, $tableName, $data, $is_binary);

        $after = 0;
        try {
            $after = intval($db->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $tableName) . '"')->fetchColumn());
        } catch (Exception $e) {}

        return $after - $before;
    }

    // sinkronisasi tabel relasional states
    function sync_relational_states($db, $statesData) {
        if (!is_array($statesData)) {
            return 0;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS states_meta (
            metadata_id SERIAL PRIMARY KEY,
            entity_id VARCHAR(255) UNIQUE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS state_attributes (
            attributes_id SERIAL PRIMARY KEY,
            shared_attrs TEXT
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS states (
            state_id SERIAL PRIMARY KEY,
            metadata_id INTEGER,
            attributes_id INTEGER,
            state VARCHAR(255),
            last_changed_ts DOUBLE PRECISION,
            last_updated_ts DOUBLE PRECISION,
            last_reported_ts DOUBLE PRECISION
        )");

        $new_rows_count = 0;

        foreach ($statesData as $item) {
            $entity_id = $item['entity_id'] ?? '';
            if (empty($entity_id)) continue;

            $stmt = $db->prepare("INSERT INTO states_meta (entity_id) VALUES (?) ON CONFLICT (entity_id) DO NOTHING");
            $stmt->execute([$entity_id]);

            $stmt = $db->prepare("SELECT metadata_id FROM states_meta WHERE entity_id = ?");
            $stmt->execute([$entity_id]);
            $metadata_id = $stmt->fetchColumn();

            if (!$metadata_id) continue;

            $last_changed_ts = !empty($item['last_changed']) ? (float)strtotime($item['last_changed']) : null;
            $last_updated_ts = !empty($item['last_updated']) ? (float)strtotime($item['last_updated']) : null;
            $last_reported_ts = $last_updated_ts;


            $shared_attrs = json_encode($item['attributes'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("INSERT INTO state_attributes (shared_attrs) VALUES (?)");
            $stmt->execute([$shared_attrs]);
            $attributes_id = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO states
                (metadata_id, attributes_id, state, last_changed_ts, last_updated_ts, last_reported_ts)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $metadata_id,
                $attributes_id,
                $item['state'] ?? '',
                $last_changed_ts,
                $last_updated_ts,
                $last_reported_ts
            ]);

            $new_rows_count++;
        }

        return $new_rows_count;
    }

    $target_date_input = $_GET['date'] ?? '';
    if (!empty($target_date_input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date_input)) {
        $base_time = strtotime($target_date_input);
    } else {
        $base_time = time();
    }

    $target_time = strtotime('-1 day', $base_time);

    $yesterday = date('Y-m-d\T00:00:00\Z', $target_time);
    $today_time = strtotime('+1 day', $target_time);
    $today_start = date('Y-m-d\T00:00:00\Z', $today_time);
    $today_end = date('Y-m-d\T23:59:59\Z', $today_time);

    // api/
    log_scheduler($db, 'FETCHING', 'Menghubungkan ke Home Assistant API...', 'api/');
    $api_root = fetch_ha_api($ha_url, $ha_token, 'api/');
    log_scheduler($db, 'FETCHED', 'Berhasil terhubung ke Home Assistant API.', 'api/');
    $n = sync_and_count($db, 'api_root', $api_root);
    log_scheduler($db, 'SYNCED', "api_root: $n baris ditambahkan.", 'api/');

    // api/config
    log_scheduler($db, 'FETCHING', 'Mengambil konfigurasi HA...', 'api/config');
    $api_config = fetch_ha_api($ha_url, $ha_token, 'api/config');
    log_scheduler($db, 'FETCHED', 'Data api/config berhasil diterima.', 'api/config');
    $n = sync_and_count($db, 'api_config', $api_config);
    log_scheduler($db, 'SYNCED', "api_config: $n baris ditambahkan.", 'api/config');

    // api/components
    log_scheduler($db, 'FETCHING', 'Mengambil daftar komponen...', 'api/components');
    $api_components = fetch_ha_api($ha_url, $ha_token, 'api/components');
    log_scheduler($db, 'FETCHED', 'Data api/components berhasil diterima.', 'api/components');
    $n = sync_and_count($db, 'api_components', $api_components);
    log_scheduler($db, 'SYNCED', "api_components: $n baris ditambahkan.", 'api/components');

    // api/events
    log_scheduler($db, 'FETCHING', 'Mengambil daftar event...', 'api/events');
    $api_events = fetch_ha_api($ha_url, $ha_token, 'api/events');
    log_scheduler($db, 'FETCHED', 'Data api/events berhasil diterima.', 'api/events');
    $n = sync_and_count($db, 'api_events', $api_events);
    log_scheduler($db, 'SYNCED', "api_events: $n baris ditambahkan.", 'api/events');

    // api/services
    log_scheduler($db, 'FETCHING', 'Mengambil daftar services...', 'api/services');
    $api_services = fetch_ha_api($ha_url, $ha_token, 'api/services');
    log_scheduler($db, 'FETCHED', 'Data api/services berhasil diterima.', 'api/services');
    $n = sync_and_count($db, 'api_services', $api_services);
    log_scheduler($db, 'SYNCED', "api_services: $n baris ditambahkan.", 'api/services');

    // api/states
    // entity_id dipakai untuk history dan kamera
    log_scheduler($db, 'FETCHING', 'Mengambil semua states entity...', 'api/states');
    $api_states = fetch_ha_api($ha_url, $ha_token, 'api/states');
    $state_count = is_array($api_states) ? count($api_states) : 0;
    log_scheduler($db, 'FETCHED', "Data api/states diterima: $state_count entity.", 'api/states');
    $n = sync_and_count($db, 'api_states', $api_states);
    log_scheduler($db, 'SYNCED', "api_states: $n baris baru dari $state_count entity (sisanya sudah ada / dedup).", 'api/states');

    // sinkronisasi relasional states
    $n_rel = sync_relational_states($db, $api_states);
    log_scheduler($db, 'SYNCED', "Relational states: $n_rel baris baru ditambahkan ke tabel states (dedup).", 'api/states');

    // ambil entity yang dipakai endpoint lain
    $entities  = [];
    $cameras   = [];
    $calendars = [];

    if (is_array($api_states)) {
        foreach ($api_states as $state) {
            $entity_id = $state['entity_id'] ?? '';
            if ($entity_id) {
                $entities[] = $entity_id;
                $domain = explode('.', $entity_id)[0] ?? '';
                if ($domain === 'camera') {
                    $cameras[] = $entity_id;
                }
            }
        }
    }

    // api/history
    // batch 50 entity, payload ringan, ambil data kemarin saja
    $history_batch_size = 50;
    $history_batches    = !empty($entities) ? array_chunk($entities, $history_batch_size) : [[]];
    $history_total_new  = 0;
    $history_batch_num  = 0;

    foreach ($history_batches as $batch) {
        $history_batch_num++;
        $base_endpoint = "api/history/period/$yesterday";
        $params = [
            'end_time'                => $today_start,
            'minimal_response'        => 'true',
            'no_attributes'           => 'true',
            'significant_changes_only'=> 'true',
        ];
        if (!empty($batch)) {
            $params['filter_entity_id'] = implode(',', $batch);
        }
        $query_string   = '?' . http_build_query($params);
        $full_endpoint  = $base_endpoint . $query_string;
        $batch_label    = "batch $history_batch_num/" . count($history_batches);

        try {
            log_scheduler($db, 'FETCHING', "History $batch_label (" . count($batch) . " entity)...", $base_endpoint);
            $api_history = fetch_ha_api($ha_url, $ha_token, $full_endpoint);
            log_scheduler($db, 'FETCHED', "History $batch_label berhasil diterima.", $base_endpoint);
            $n = sync_and_count($db, 'api_history', $api_history);
            $history_total_new += $n;
            log_scheduler($db, 'SYNCED', "api_history $batch_label: $n baris baru (dedup by entity_id+last_updated).", $base_endpoint);
        } catch (Exception $ex) {
            log_scheduler($db, 'ERROR', "Gagal history $batch_label: " . $ex->getMessage(), $base_endpoint);
        }
    }
    log_scheduler($db, 'SYNCED', "api_history TOTAL: $history_total_new baris baru dari " . count($entities) . " entity.", 'api/history/period');

    // api/logbook
    // per entity, ambil data kemarin saja
    $logbook_total_new = 0;
    $logbook_base      = "api/logbook/$yesterday";

    foreach ($entities as $entity_id) {
        $params = [
            'entity'   => $entity_id,
            'end_time' => $today_start,
        ];
        $full_endpoint = $logbook_base . '?' . http_build_query($params);

        try {
            log_scheduler($db, 'FETCHING', "Logbook entity: $entity_id...", $logbook_base);
            $api_logbook = fetch_ha_api($ha_url, $ha_token, $full_endpoint);
            log_scheduler($db, 'FETCHED', "Logbook $entity_id diterima.", $logbook_base);
            $n = sync_and_count($db, 'api_logbook', $api_logbook);
            $logbook_total_new += $n;
            log_scheduler($db, 'SYNCED', "api_logbook [$entity_id]: $n baris baru (dedup by when).", $logbook_base);
        } catch (Exception $ex) {
            log_scheduler($db, 'ERROR', "Gagal logbook [$entity_id]: " . $ex->getMessage(), $logbook_base);
        }
    }
    log_scheduler($db, 'SYNCED', "api_logbook TOTAL: $logbook_total_new baris baru dari " . count($entities) . " entity.", 'api/logbook');

    // api/states/<entity_id>
    // semua entity, skip jika data sudah ada
    $state_detail_total_new = 0;
    $state_detail_skipped   = 0;

    foreach ($entities as $entity_id) {
        $state_endpoint = "api/states/$entity_id";
        try {
            log_scheduler($db, 'FETCHING', "Detail state: $entity_id...", $state_endpoint);
            $state_detail = fetch_ha_api($ha_url, $ha_token, $state_endpoint);
            log_scheduler($db, 'FETCHED', "Detail state $entity_id diterima.", $state_endpoint);
            $n = sync_and_count($db, 'api_states_details', $state_detail);
            if ($n > 0) {
                $state_detail_total_new++;
                log_scheduler($db, 'SYNCED', "api_states_details [$entity_id]: 1 baris baru.", $state_endpoint);
            } else {
                $state_detail_skipped++;
                // skip log detail jika data sama
            }
        } catch (Exception $ex) {
            log_scheduler($db, 'ERROR', "Gagal detail state [$entity_id]: " . $ex->getMessage(), $state_endpoint);
        }
    }
    log_scheduler($db, 'SYNCED',
        "api_states_details TOTAL: $state_detail_total_new baru, $state_detail_skipped sudah ada (dedup by entity_id+last_updated), dari " . count($entities) . " entity.",
        'api/states/<entity_id>'
    );

    // api/error_log
    try {
        log_scheduler($db, 'FETCHING', 'Mengambil error log HA...', 'api/error_log');
        $api_error_log = fetch_ha_api($ha_url, $ha_token, 'api/error_log');
        log_scheduler($db, 'FETCHED', 'Error log berhasil diterima.', 'api/error_log');
        $n = sync_and_count($db, 'api_error_log', $api_error_log);
        log_scheduler($db, 'SYNCED', "api_error_log: $n baris ditambahkan.", 'api/error_log');
    } catch (Exception $ex) {
        log_scheduler($db, 'ERROR', 'Gagal mengambil error log: ' . $ex->getMessage(), 'api/error_log');
    }

    // api/camera_proxy
    foreach ($cameras as $camera_id) {
        $cam_endpoint = "api/camera_proxy/$camera_id";
        try {
            log_scheduler($db, 'FETCHING', 'Mengambil gambar kamera...', $cam_endpoint);
            $image_bytes = fetch_ha_api($ha_url, $ha_token, $cam_endpoint, true);
            log_scheduler($db, 'FETCHED', 'Gambar kamera berhasil diterima (' . strlen($image_bytes) . ' bytes).', $cam_endpoint);
            $n = sync_and_count($db, 'api_camera_proxy', [
                'entity_id'   => $camera_id,
                'image_bytes' => $image_bytes
            ], true);
            log_scheduler($db, 'SYNCED', "api_camera_proxy [$camera_id]: $n baris ditambahkan.", $cam_endpoint);
        } catch (Exception $ex) {
            log_scheduler($db, 'ERROR', 'Gagal mengambil kamera: ' . $ex->getMessage(), $cam_endpoint);
        }
    }

    // api/calendars
    log_scheduler($db, 'FETCHING', 'Mengambil daftar kalender...', 'api/calendars');
    $api_calendars = fetch_ha_api($ha_url, $ha_token, 'api/calendars');
    $cal_count = is_array($api_calendars) ? count($api_calendars) : 0;
    log_scheduler($db, 'FETCHED', "Data kalender diterima: $cal_count kalender.", 'api/calendars');
    $n = sync_and_count($db, 'api_calendars', $api_calendars);
    log_scheduler($db, 'SYNCED', "api_calendars: $n baris ditambahkan.", 'api/calendars');

    if (is_array($api_calendars)) {
        foreach ($api_calendars as $cal) {
            if (isset($cal['entity_id'])) {
                $calendars[] = $cal['entity_id'];
            }
        }
    }

    // api/calendar events
    foreach ($calendars as $calendar_id) {
        $cal_endpoint = "api/calendars/$calendar_id";
        $cal_endpoint_full = $cal_endpoint . '?start=' . urlencode($today_start) . '&end=' . urlencode($today_end);
        try {
            log_scheduler($db, 'FETCHING', 'Mengambil event kalender...', $cal_endpoint);
            $cal_events = fetch_ha_api($ha_url, $ha_token, $cal_endpoint_full);
            log_scheduler($db, 'FETCHED', 'Event kalender berhasil diterima.', $cal_endpoint);
            $n = sync_and_count($db, 'api_calendar_events', $cal_events);
            log_scheduler($db, 'SYNCED', "api_calendar_events [$calendar_id]: $n baris baru (dedup by start).", $cal_endpoint);
        } catch (Exception $ex) {
            log_scheduler($db, 'ERROR', 'Gagal mengambil event kalender: ' . $ex->getMessage(), $cal_endpoint);
        }
    }

    log_scheduler($db, 'SUCCESS', 'Semua endpoint selesai diproses.', null);
    echo json_encode(['status' => 'success', 'message' => 'Scheduler completed.']);

} catch (Exception $e) {
    log_scheduler($db, 'FAILED', 'Fatal error: ' . $e->getMessage(), null);
    http_response_code(500);
    echo json_encode(['status' => 'failed', 'message' => $e->getMessage()]);
}
