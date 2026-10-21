<?php

/**
 * scheduler.php
 *
 * Entry point untuk menjalankan pipeline preprocessing sensor IoT.
 * Memanggil ProcessSensorData Job yang mengorkestrasi seluruh alur:
 *
 *   Home Assistant → Identifikasi Sensor → Standardisasi
 *   → Missing Value Detection → Anomaly Detection
 *   → Data Cleaning → Data Quality Assessment → Database Bersih
 *
 * Dapat dipanggil:
 *   - HTTP: http://localhost/home-assistant/scheduler.php[?date=YYYY-MM-DD]
 *   - CLI:  php scheduler.php [--date=YYYY-MM-DD]
 *   - Service Worker otomatis (sw.js) pukul 00:01
 */

set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_api.php';
require_once __DIR__ . '/app/Jobs/ProcessSensorData.php';

// Batasi akses HTTP hanya dari localhost
if (php_sapi_name() !== 'cli') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIp, ['127.0.0.1', '::1'])) {
        http_response_code(403);
        exit('Forbidden');
    }
}

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

if ($date !== null) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "Error: Invalid date format. Use YYYY-MM-DD\n");
            exit(1);
        }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid date format. Expected YYYY-MM-DD']);
        exit;
    }
}

$job = new ProcessSensorData($db, $ha_url, $ha_token, $date);
$job->initSchema();
$result = $job->run();

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
