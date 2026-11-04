<?php

require 'config_api.php';

// inisialisasi data
$error   = null;
$data    = [];
$filter  = strtolower(trim($_GET['filter'] ?? ''));
$fetchedAt = date('d/m/Y H:i:s');

// request api
$options = [
    'http' => [
        'header'  =>
            "Authorization: Bearer $ha_token\r\n" .
            "Content-Type: application/json\r\n",
        'timeout' => 10,
    ]
];

// get data states
$context  = stream_context_create($options);
$response = @file_get_contents("$ha_url/api/states", false, $context);
$isOffline = false;

// response api
if ($response === false) {
    // offline fallback
    $isOffline = true;
    try {
        if (!isset($db)) {
            require_once 'config.php';
        }
        $cachedData = $db->query("
            SELECT a.entity_id, a.state, a.attributes, a.last_changed, a.last_updated
            FROM api_states a
            INNER JOIN (
                SELECT entity_id, MAX(id) as max_id
                FROM api_states
                GROUP BY entity_id
            ) b ON a.id = b.max_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $decoded = [];
        foreach ($cachedData as $row) {
            $decoded[] = [
                'entity_id'    => $row['entity_id'],
                'state'        => $row['state'],
                'attributes'   => json_decode($row['attributes'], true) ?? [],
                'last_changed' => $row['last_changed'],
                'last_updated' => $row['last_updated']
            ];
        }
        $data = $decoded;
    } catch (Exception $e) {
        $error = "Gagal terhubung ke Home Assistant API ($ha_url) dan gagal memuat cache database local: " . $e->getMessage();
    }
} else {
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $error = "Response dari API tidak valid.";
    } else {
        $data = $decoded;
    }
}

// Jalankan pipeline ETL otomatis ke database PostgreSQL (sensor_mapping, sensor_readings, sensor_quality)
$pipelineSummary = null;
$pipelineError   = null;
$allEntities     = ($response === false) ? $data : ($decoded ?? []);

if (!empty($allEntities)) {
    try {
        if (!isset($db)) {
            require_once 'config.php';
        }
        require_once __DIR__ . '/app/Jobs/ProcessSensorData.php';
        $job = new ProcessSensorData($db, $ha_url, $ha_token);
        $job->setVerbose(false);
        $job->setHaOffline($isOffline);
        $job->initSchema();
        $pipelineSummary = $job->run($allEntities);
    } catch (\Throwable $e) {
        $pipelineError = $e->getMessage();
        error_log("Live API Pipeline Error: " . $e->getMessage());
    }
}

// filter domain
if ($filter !== '' && count($data) > 0) {
    $data = array_values(array_filter($data, function($item) use ($filter) {
        return str_starts_with(strtolower($item['entity_id'] ?? ''), $filter . '.');
    }));
}

// daftar domain
$domains = [];
foreach (($response === false ? $data : ($decoded ?? [])) as $item) {
    $parts = explode('.', $item['entity_id'] ?? '');
    if (isset($parts[0])) $domains[$parts[0]] = true;
}
ksort($domains);

?>

<div class="table-card">

    <div class="table-header live-header">

        <div class="live-header-left">
            <span class="live-indicator"></span>
            <h2>Live API</h2>
            <span class="live-meta">
                Diperbarui: <strong><?= $fetchedAt ?></strong>
                &nbsp;·&nbsp;
                <?= count($data) ?> entity
                <?php if ($pipelineSummary): ?>
                    &nbsp;·&nbsp;
                    <span class="badge success" style="font-size: 11px; padding: 2px 7px;">
                        ✓ ETL: <?= $pipelineSummary['readings_saved'] ?> data diproses (<?= $pipelineSummary['entities_processed'] ?> sensor)
                    </span>
                <?php elseif ($pipelineError): ?>
                    &nbsp;·&nbsp;
                    <span class="badge danger" style="font-size: 11px; padding: 2px 7px;">
                        ⚠️ ETL Error: <?= htmlspecialchars($pipelineError) ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>

        <div class="live-header-right">

            <form method="GET" class="live-filter-form" id="filterForm">
                <input type="hidden" name="page" value="live">
                <select name="filter" id="domainFilter" onchange="document.getElementById('filterForm').submit()">
                    <option value="">Semua Domain</option>
                    <?php foreach (array_keys($domains) as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"
                            <?= ($filter === $d) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a href="?page=live<?= $filter ? '&filter='.urlencode($filter) : '' ?>" class="btn-refresh" id="btnRefresh">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
                Refresh
            </a>

        </div>

    </div>

    <div class="table-responsive">

        <?php if ($error): ?>

            <div class="api-error">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>

        <?php elseif (count($data) === 0): ?>

            <div class="api-error">Tidak ada entity ditemukan.</div>

        <?php else: ?>

            <table class="modern-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Entity ID</th>
                        <th>State</th>
                        <th>Friendly Name</th>
                        <th>Last Changed</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $i => $item):
                        $state       = $item['state'] ?? '';
                        $entityId    = $item['entity_id'] ?? '';
                        $attrs       = $item['attributes'] ?? [];
                        $friendlyName = $attrs['friendly_name'] ?? '-';

                        // format timestamp
                        $lastChanged = '';
                        if (!empty($item['last_changed'])) {
                            try {
                                $dt = new DateTime($item['last_changed'], new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                                $lastChanged = $dt->format('d/m/Y H:i:s');
                            } catch (Exception $e) { $lastChanged = $item['last_changed']; }
                        }

                        // format timestamp
                        $lastUpdated = '';
                        if (!empty($item['last_updated'])) {
                            try {
                                $dt = new DateTime($item['last_updated'], new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                                $lastUpdated = $dt->format('d/m/Y H:i:s');
                            } catch (Exception $e) { $lastUpdated = $item['last_updated']; }
                        }

                        // state color
                        $stateClass = match(strtolower($state)) {
                            'on'          => 'success',
                            'off'         => 'danger',
                            'unavailable' => 'warning',
                            'unknown'     => 'muted',
                            default       => 'neutral',
                        };
                    ?>
                        <tr>
                            <td class="row-num"><?= $i + 1 ?></td>
                            <td class="entity-id"><?= htmlspecialchars($entityId) ?></td>
                            <td>
                                <span class="badge <?= $stateClass ?>">
                                    <?= htmlspecialchars($state) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($friendlyName) ?></td>
                            <td>
                                <?php if ($lastChanged): ?>
                                    <span class="badge timestamp" title="<?= htmlspecialchars($item['last_changed']) ?>">
                                        <?= $lastChanged ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lastUpdated): ?>
                                    <span class="badge timestamp" title="<?= htmlspecialchars($item['last_updated']) ?>">
                                        <?= $lastUpdated ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>
