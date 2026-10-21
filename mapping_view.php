<?php

/**
 * mapping_view.php
 *
 * Halaman Sensor Mapping
 * Menampilkan tabel sensor_mapping: entity_id asli → standar nama, kategori, satuan.
 * Mencakup statistik consistency dan tombol trigger re-mapping.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/Models/Sensor.php';
require_once __DIR__ . '/app/Services/SensorMappingService.php';

Sensor::setDb($db);

$sensors          = [];
$consistencyStats = [];
$error            = null;
$message          = null;

try {
    Sensor::createTable();

    // Trigger re-mapping jika diminta
    if (isset($_GET['remap']) && $_GET['remap'] === '1') {
        require_once __DIR__ . '/config_api.php';
        $mappingService = new SensorMappingService();

        // Ambil states dari HA
        $options  = [
            'http' => [
                'header'  => "Authorization: Bearer {$ha_token}\r\nContent-Type: application/json\r\n",
                'timeout' => 15,
            ],
        ];
        $ctx      = stream_context_create($options);
        $response = @file_get_contents("{$ha_url}/api/states", false, $ctx);

        if ($response !== false) {
            $states  = json_decode($response, true) ?? [];
            $mapped  = $mappingService->processBatch($states);
            $message = 'Re-mapping selesai: ' . count($mapped) . ' sensor diproses.';
        } else {
            $message = '⚠️ Tidak dapat terhubung ke Home Assistant API.';
        }
    }

    $sensors          = Sensor::all();
    $consistencyStats = (new SensorMappingService())->calculateNamingConsistency();

} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Kelompokkan per kategori
$byCategory = [];
foreach ($sensors as $s) {
    $cat = $s['category'] ?? 'Unknown';
    $byCategory[$cat][] = $s;
}
ksort($byCategory);
?>

<div class="table-card">
    <div class="table-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem">
        <div>
            <h2>🗂️ Sensor Mapping</h2>
            <p style="color:var(--text-muted);margin:0.25rem 0 0;font-size:0.9rem">
                Standardisasi entitas: entity_id → nama standar, kategori, jenis pengukuran, satuan
            </p>
        </div>
        <a href="?page=mapping&remap=1"
           style="padding:0.5rem 1.25rem;background:var(--accent,#6366f1);color:#fff;border-radius:8px;text-decoration:none;font-size:0.875rem;white-space:nowrap">
            🔄 Re-mapping dari HA
        </a>
    </div>

    <?php if ($error): ?>
        <div style="padding:1rem;color:#f87171">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div style="padding:0.75rem 1.5rem;background:#134e4a;color:#6ee7b7;border-radius:0;font-size:0.875rem">
            ✅ <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistik Consistency -->
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)">
        <div>
            <span style="color:var(--text-muted);font-size:0.8rem">Total Sensor</span>
            <div style="font-size:1.5rem;font-weight:700"><?= $consistencyStats['total'] ?? 0 ?></div>
        </div>
        <div>
            <span style="color:var(--text-muted);font-size:0.8rem">Termapping</span>
            <div style="font-size:1.5rem;font-weight:700;color:#4ade80"><?= $consistencyStats['mapped'] ?? 0 ?></div>
        </div>
        <div>
            <span style="color:var(--text-muted);font-size:0.8rem">Belum Termapping</span>
            <div style="font-size:1.5rem;font-weight:700;color:#f87171"><?= $consistencyStats['unmapped'] ?? 0 ?></div>
        </div>
        <div>
            <span style="color:var(--text-muted);font-size:0.8rem">Naming Consistency</span>
            <?php $c = $consistencyStats['consistency'] ?? 0; ?>
            <div style="font-size:1.5rem;font-weight:700;color:<?= $c >= 80 ? '#4ade80' : ($c >= 60 ? '#facc15' : '#f87171') ?>">
                <?= number_format($c, 2) ?>%
            </div>
        </div>
    </div>

    <!-- Tabel Mapping -->
    <?php if (!empty($sensors)): ?>
    <div style="padding:1.25rem 1.5rem">
        <div style="overflow-x:auto">
            <table id="mapping-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th>Entity ID (Asli)</th>
                        <th>Standard Name</th>
                        <th>Kategori</th>
                        <th>Jenis Pengukuran</th>
                        <th>Satuan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sensors as $s): ?>
                    <tr>
                        <td><code style="font-size:0.8rem"><?= htmlspecialchars($s['entity_id']) ?></code></td>
                        <td><?= htmlspecialchars($s['standard_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['category'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['measurement_type'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['unit'] ?? '-') ?></td>
                        <td>
                            <?php if ($s['is_mapped']): ?>
                                <span style="color:#4ade80;font-size:0.8rem">✔ Mapped</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:0.8rem">— Unmapped</span>
                            <?php endif; ?>
                        </td>
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
        $('#mapping-table').DataTable({
            pageLength: 25,
            order: [[2, 'asc'], [0, 'asc']],
        });
    });
    </script>

    <?php else: ?>
    <div style="padding:2rem 1.5rem;text-align:center;color:var(--text-muted)">
        <p>Belum ada data mapping sensor.</p>
        <p>Klik <strong>Re-mapping dari HA</strong> untuk mengambil dan memetakan sensor dari Home Assistant.</p>
    </div>
    <?php endif; ?>
</div>
