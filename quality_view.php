<?php

/**
 * quality_view.php
 *
 * Dashboard Data Quality Assessment
 * Menampilkan metrik: Completeness, Validity, Consistency, Timeliness
 * per sensor, plus tabel perbandingan sebelum/sesudah cleaning.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/Models/SensorQuality.php';
require_once __DIR__ . '/app/Models/Sensor.php';
require_once __DIR__ . '/app/Services/DataQualityService.php';
require_once __DIR__ . '/app/Services/SensorMappingService.php';

SensorQuality::setDb($db);
Sensor::setDb($db);

// Coba baca data quality dari DB
$qualitySummary = [];
$consistencyStats = [];

try {
    SensorQuality::createTable();
    $qualitySummary   = SensorQuality::summary();
    $consistencyStats = (new DataQualityService())->calculateConsistency();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Format angka %
function pct(float $val): string {
    $color = $val >= 80 ? '#4ade80' : ($val >= 60 ? '#facc15' : '#f87171');
    return "<span style=\"color:{$color};font-weight:700\">" . number_format($val, 2) . "%</span>";
}
?>

<div class="table-card">
    <div class="table-header">
        <h2>📊 Data Quality Assessment</h2>
        <p style="color:var(--text-muted);margin:0.25rem 0 0">
            Completeness · Validity · Consistency · Timeliness
        </p>
    </div>

    <?php if (!empty($error)): ?>
        <div style="padding:1rem;color:#f87171">
            ⚠️ <?= htmlspecialchars($error) ?>
            <br><small>Pastikan pipeline sudah dijalankan terlebih dahulu via
            <code><a href="scheduler.php" target="_blank">scheduler.php</a></code>.</small>
        </div>
    <?php endif; ?>

    <!-- Ringkasan Consistency Global -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)">
        <div class="stat-card">
            <div class="stat-label">Naming Consistency</div>
            <div class="stat-value"><?= pct($consistencyStats['naming_consistency'] ?? 0) ?></div>
            <small><?= ($consistencyStats['mapped_sensors'] ?? 0) ?> / <?= ($consistencyStats['total_sensors'] ?? 0) ?> sensor termapping</small>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unit Consistency</div>
            <div class="stat-value"><?= pct($consistencyStats['unit_consistency'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Category Consistency</div>
            <div class="stat-value"><?= pct($consistencyStats['category_consistency'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Overall Consistency</div>
            <div class="stat-value"><?= pct($consistencyStats['consistency'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Tabel per Sensor -->
    <?php if (!empty($qualitySummary)): ?>
    <div style="padding:1.25rem 1.5rem">
        <h3 style="margin:0 0 1rem;font-size:0.95rem;color:var(--text-muted)">
            Metrik Per Sensor — Terakhir Diassess
        </h3>
        <div style="overflow-x:auto">
            <table id="quality-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>Entity ID</th>
                        <th>Completeness</th>
                        <th>Validity</th>
                        <th>Consistency</th>
                        <th>Timeliness</th>
                        <th>Assessed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qualitySummary as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($row['entity_id']) ?></code></td>
                        <td><?= pct((float)($row['completeness'] ?? 0)) ?></td>
                        <td><?= pct((float)($row['validity'] ?? 0)) ?></td>
                        <td><?= pct((float)($row['consistency'] ?? 0)) ?></td>
                        <td><?= pct((float)($row['timeliness'] ?? 0)) ?></td>
                        <td><?= htmlspecialchars($row['assessed_at'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#quality-table').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            language: { url: '' }
        });
    });
    </script>

    <?php else: ?>
    <div style="padding:2rem 1.5rem;text-align:center;color:var(--text-muted)">
        <p>Belum ada data quality assessment.</p>
        <p>Jalankan pipeline terlebih dahulu:</p>
        <code style="background:var(--bg-secondary);padding:0.5rem 1rem;border-radius:6px">
            php app/Jobs/ProcessSensorData.php
        </code>
        <br><br>
        <a href="scheduler.php" target="_blank"
           style="display:inline-block;padding:0.5rem 1.25rem;background:var(--accent);color:#fff;border-radius:6px;text-decoration:none">
            ▶ Jalankan via Browser
        </a>
    </div>
    <?php endif; ?>

</div>

<!-- Panduan Metrik -->
<div class="table-card" style="margin-top:1rem">
    <div class="table-header">
        <h3>📖 Keterangan Metrik</h3>
    </div>
    <div style="padding:1rem 1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem">
        <div>
            <strong>Completeness</strong>
            <p style="color:var(--text-muted);font-size:0.875rem;margin:0.25rem 0 0">
                (1 − missing/total) × 100%<br>
                Kelengkapan data yang tersedia.
            </p>
        </div>
        <div>
            <strong>Validity</strong>
            <p style="color:var(--text-muted);font-size:0.875rem;margin:0.25rem 0 0">
                (valid/diperiksa) × 100%<br>
                Nilai memenuhi aturan domain sensor.
            </p>
        </div>
        <div>
            <strong>Consistency</strong>
            <p style="color:var(--text-muted);font-size:0.875rem;margin:0.25rem 0 0">
                (termapping/total) × 100%<br>
                Keseragaman penamaan, kategori, dan satuan.
            </p>
        </div>
        <div>
            <strong>Timeliness</strong>
            <p style="color:var(--text-muted);font-size:0.875rem;margin:0.25rem 0 0">
                (tepat_waktu/diharapkan) × 100%<br>
                Sensor melapor sesuai interval yang diharapkan.
            </p>
        </div>
    </div>
</div>

<style>
.stat-card {
    background: var(--bg-secondary, #1e293b);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    border: 1px solid var(--border, #334155);
}
.stat-label {
    font-size: 0.78rem;
    color: var(--text-muted, #94a3b8);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 0.4rem;
}
.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}
</style>
