<?php

require 'config.php';
require 'sensor_config.php';

$sensorKey = $_GET['sensor'] ?? '';

if (!isset($sensorMenus[$sensorKey])) {
    echo '<div class="table-card"><div class="table-header"><h2>Pilih menu sensor dari sidebar.</h2></div></div>';
    return;
}

$cfg    = $sensorMenus[$sensorKey];
$type   = $cfg['type'];
$filter = $cfg['filter'];
$label  = $cfg['label'];
$cols   = $cfg['columns'];
$limit  = 1000000;

$rows    = [];
$error   = null;

if (!function_exists('format_sensor_timestamp')) {
    // format waktu
    function format_sensor_timestamp($value) {
        if ($value === null || $value === '') {
            return 'N/A';
        }

        try {
            if (is_numeric($value)) {
                $dt = new DateTime('@' . round($value), new DateTimeZone('UTC'));
            } else {
                $dt = new DateTime($value, new DateTimeZone('UTC'));
            }
            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
            return $dt->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return $value;
        }
    }
}

$legacyDbPath = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'home-assistant_v2 1.db';
// Semua tabel (termasuk events, event_data, event_types) kini berada di schema 'public' PostgreSQL.
// Tidak diperlukan ATTACH DATABASE atau prefix schema ha_asli lagi.

$relationalStateSql = "
    SELECT  states.state_id AS id,
            states.state AS state,
            states.last_reported_ts AS ts,
            state_attributes.shared_attrs
    FROM states
    JOIN state_attributes ON state_attributes.attributes_id = states.attributes_id
    JOIN states_meta ON states_meta.metadata_id = states.metadata_id
    WHERE state_attributes.shared_attrs ILIKE :filter
    ORDER BY states.state_id DESC
    LIMIT :limit
";

$relationalAkarSql = "
    SELECT  states_meta.entity_id AS hash,
            state_attributes.shared_attrs
    FROM states
    JOIN state_attributes ON state_attributes.attributes_id = states.attributes_id
    JOIN states_meta ON states_meta.metadata_id = states.metadata_id
    WHERE state_attributes.shared_attrs ILIKE :filter
    ORDER BY states.state_id DESC
    LIMIT :limit
";

try {

    if ($type === 'akar') {
        $stmt = $db->prepare($relationalAkarSql);
        $stmt->bindValue(':filter', $filter);
        $stmt->bindValue(':limit',  $limit, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $attrs = json_decode($row['shared_attrs'], true);
            if (!is_array($attrs)) continue;
            $rows[] = [
                'id'           => $row['hash'],
                'state_class'  => $attrs['state_class']         ?? 'N/A',
                'unit'         => $attrs['unit_of_measurement'] ?? 'N/A',
                'device_class' => $attrs['device_class']        ?? 'N/A',
                'friendly'     => $attrs['friendly_name']       ?? 'N/A',
            ];
        }

    } elseif ($type === 'sensor') {
        $stmt = $db->prepare($relationalStateSql);
        $stmt->bindValue(':filter', $filter);
        $stmt->bindValue(':limit',  $limit, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $attrs = json_decode($row['shared_attrs'], true);
            if (!is_array($attrs)) continue;
            $rows[] = [
                'id'           => $row['id'],
                'ts'           => format_sensor_timestamp($row['ts']),
                'state'        => $cfg['round_state'] ? round((float)$row['state'], 2) : $row['state'],
                'last_meas'    => $attrs['last_measured']       ?? 'N/A',
                'state_class'  => $attrs['state_class']         ?? 'N/A',
                'unit'         => $attrs['unit_of_measurement'] ?? 'N/A',
                'device_class' => $attrs['device_class']        ?? 'N/A',
                'friendly'     => $attrs['friendly_name']       ?? 'N/A',
            ];
        }

    } elseif ($type === 'kandungan') {
        $kandunganSql = "
            SELECT  states.state_id AS id,
                    states.state AS state,
                    states.last_reported_ts AS ts,
                    state_attributes.shared_attrs
            FROM states
            JOIN state_attributes ON state_attributes.attributes_id = states.attributes_id
            JOIN states_meta ON states_meta.metadata_id = states.metadata_id
            WHERE states_meta.entity_id = :filter
            ORDER BY states.state_id DESC
            LIMIT :limit
        ";
        $stmt = $db->prepare($kandunganSql);
        $stmt->bindValue(':filter', $filter);
        $stmt->bindValue(':limit',  $limit, PDO::PARAM_INT);
        $stmt->execute();

        $fieldKey = $cfg['field'] ?? '';
        $unitLabel = $cfg['unit'] ?? 'N/A';

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $attrs = json_decode($row['shared_attrs'], true);
            if (!is_array($attrs)) continue;

            $stateVal = isset($attrs[$fieldKey]) ? $attrs[$fieldKey] : 'N/A';

            $rows[] = [
                'id'       => $row['id'],
                'ts'       => format_sensor_timestamp($row['ts']),
                'state'    => $stateVal,
                'unit'     => $unitLabel,
                'friendly' => ($attrs['friendly_name'] ?? 'N/A') . ' (' . strtoupper($fieldKey) . ')',
            ];
        }

    } elseif ($type === 'event') {
        // get event log - semua tabel kini di schema public
        $sql = "
            SELECT  event_data.hash,
                    to_char(to_timestamp(events.time_fired_ts), 'YYYY-MM-DD HH24:MI:SS') AS ts,
                    event_types.event_type,
                    event_data.shared_data
            FROM events
            LEFT JOIN event_types ON events.event_type_id = event_types.event_type_id
            LEFT JOIN event_data  ON events.data_id       = event_data.data_id
            WHERE event_data.shared_data ILIKE :filter
            ORDER BY event_types.event_type, events.time_fired_ts
            LIMIT :limit
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':filter', $filter);
        $stmt->bindValue(':limit',  500, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data       = json_decode($row['shared_data'], true);
            $domain     = 'N/A';
            $service    = 'N/A';
            $entity_ids = 'N/A';

            if (is_array($data)) {
                $domain     = $data['domain']  ?? 'N/A';
                $service    = $data['service'] ?? 'N/A';
                $svcData    = $data['service_data'] ?? [];
                $eids       = $svcData['entity_id'] ?? [];
                if (is_array($eids) && !empty($eids)) {
                    $entity_ids = implode(', ', $eids);
                }
            }

            $rows[] = [
                'hash'       => $row['hash'],
                'event_type' => $row['event_type'],
                'ts'         => format_sensor_timestamp($row['ts']),
                'domain'     => $domain,
                'service'    => $service,
                'entity_id'  => $entity_ids,
            ];
        }

    } elseif ($type === 'event_callservice') {
        // get call service - semua tabel kini di schema public
        $sql = "
            SELECT  events.event_id,
                    to_char(to_timestamp(events.time_fired_ts), 'YYYY-MM-DD HH24:MI:SS') AS ts,
                    event_types.event_type,
                    event_data.hash,
                    event_data.shared_data
            FROM events
            LEFT JOIN event_types ON events.event_type_id = event_types.event_type_id
            LEFT JOIN event_data  ON events.data_id       = event_data.data_id
            WHERE event_types.event_type = 'call_service'
            ORDER BY event_types.event_type, events.time_fired_ts
            LIMIT :limit
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', 500, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data    = json_decode($row['shared_data'], true);
            $domain  = 'N/A';
            $service = 'N/A';

            if (is_array($data)) {
                $domain  = $data['domain']  ?? 'N/A';
                $service = $data['service'] ?? 'N/A';
            }

            $rows[] = [
                'hash'       => $row['hash'],
                'event_id'   => $row['event_id'],
                'ts'         => format_sensor_timestamp($row['ts']),
                'event_type' => $row['event_type'],
                'domain'     => $domain,
                'service'    => $service,
            ];
        }

    } elseif ($type === 'event_change') {
        // get perubahan state - semua tabel kini di schema public
        $sql = "
            SELECT  events.event_id,
                    to_char(to_timestamp(events.time_fired_ts), 'YYYY-MM-DD HH24:MI:SS') AS ts,
                    event_types.event_type,
                    event_data.hash,
                    event_data.shared_data
            FROM events
            LEFT JOIN event_types ON events.event_type_id = event_types.event_type_id
            LEFT JOIN event_data  ON events.data_id       = event_data.data_id
            WHERE event_types.event_type = 'call_service'
            ORDER BY event_types.event_type, events.time_fired_ts
            LIMIT :limit
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', 500, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data    = json_decode($row['shared_data'], true);
            $action    = 'N/A';
            $entity_id = 'N/A';
            $changes   = 'N/A';

            if (is_array($data)) {
                $action    = $data['action']    ?? ($data['domain'] ?? 'N/A');
                $entity_id = $data['entity_id'] ?? (isset($data['service_data']['entity_id'])
                    ? (is_array($data['service_data']['entity_id'])
                        ? implode(', ', $data['service_data']['entity_id'])
                        : $data['service_data']['entity_id'])
                    : 'N/A');
                $changesArr = $data['changes'] ?? [];
                $changes    = is_array($changesArr) && !empty($changesArr)
                    ? implode(', ', array_keys($changesArr))
                    : ($data['service'] ?? 'N/A');
            }

            $rows[] = [
                'hash'      => $row['hash'],
                'action'    => $action,
                'entity_id' => $entity_id,
                'changes'   => $changes,
            ];
        }
    }

} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>



<div class="table-card">

    <div class="table-header sensor-header">
        <div class="sensor-header-left">
            <?= sensor_icon($cfg['icon'] ?? 'database') ?>
            <h2><?= htmlspecialchars($label) ?></h2>
            <?php if (!$error): ?>
                <span class="sensor-meta"><?= number_format(count($rows)) ?> record ditemukan</span>
            <?php endif; ?>
        </div>
        <div class="sensor-header-right">
            <span class="sensor-badge-type"><?= htmlspecialchars(ucfirst($type)) ?></span>
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
                Error: <?= htmlspecialchars($error) ?>
            </div>

        <?php elseif (empty($rows)): ?>

            <div class="api-error" style="background:#fff8e1;color:#b7830a;border-color:#ffe082;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                Tidak ada data ditemukan untuk filter: <strong><?= htmlspecialchars($filter) ?></strong>
            </div>

        <?php else: ?>

            <table id="sensorDataTable" class="modern-table display" style="width:100%">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php foreach ($cols as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <tr>
                            <td class="row-num"><?= $i + 1 ?></td>

                            <?php if ($type === 'akar'): ?>
                                <td class="entity-id"><?= htmlspecialchars($r['id']) ?></td>
                                <td><span class="badge muted"><?= htmlspecialchars($r['state_class']) ?></span></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['unit']) ?></span></td>
                                <td><?= htmlspecialchars($r['device_class']) ?></td>
                                <td><?= htmlspecialchars($r['friendly']) ?></td>

                            <?php elseif ($type === 'sensor'): ?>
                                <td class="row-num"><?= htmlspecialchars($r['id']) ?></td>
                                <td><span class="badge timestamp"><?= htmlspecialchars($r['ts']) ?></span></td>
                                <?php
                                    $sv = $r['state'];
                                    $sc = is_numeric($sv) ? 'success' : (strtolower($sv) === 'unavailable' ? 'warning' : 'neutral');
                                ?>
                                <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($sv) ?></span></td>
                                <td><span class="badge timestamp"><?= htmlspecialchars($r['last_meas']) ?></span></td>
                                <td><span class="badge muted"><?= htmlspecialchars($r['state_class']) ?></span></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['unit']) ?></span></td>
                                <td><?= htmlspecialchars($r['device_class']) ?></td>
                                <td><?= htmlspecialchars($r['friendly']) ?></td>

                            <?php elseif ($type === 'kandungan'): ?>
                                <td class="row-num"><?= htmlspecialchars($r['id']) ?></td>
                                <td><span class="badge timestamp"><?= htmlspecialchars($r['ts']) ?></span></td>
                                <?php
                                    $kv = $r['state'];
                                    $kc = is_numeric($kv) ? 'success' : (strtolower($kv) === 'unavailable' ? 'warning' : 'neutral');
                                ?>
                                <td><span class="badge <?= $kc ?>"><?= htmlspecialchars($kv) ?></span></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['unit']) ?></span></td>
                                <td><?= htmlspecialchars($r['friendly']) ?></td>

                            <?php elseif ($type === 'event'): ?>
                                <td class="entity-id"><?= htmlspecialchars($r['hash']) ?></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['event_type']) ?></span></td>
                                <td><span class="badge timestamp"><?= htmlspecialchars($r['ts']) ?></span></td>
                                <td><?= htmlspecialchars($r['domain']) ?></td>
                                <td><?= htmlspecialchars($r['service']) ?></td>
                                <td class="entity-id"><?= htmlspecialchars($r['entity_id']) ?></td>

                            <?php elseif ($type === 'event_callservice'): ?>
                                <td class="entity-id"><?= htmlspecialchars($r['hash']) ?></td>
                                <td class="row-num"><?= htmlspecialchars($r['event_id']) ?></td>
                                <td><span class="badge timestamp"><?= htmlspecialchars($r['ts']) ?></span></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['event_type']) ?></span></td>
                                <td><?= htmlspecialchars($r['domain']) ?></td>
                                <td><?= htmlspecialchars($r['service']) ?></td>

                            <?php elseif ($type === 'event_change'): ?>
                                <td class="entity-id"><?= htmlspecialchars($r['hash']) ?></td>
                                <td><span class="badge neutral"><?= htmlspecialchars($r['action']) ?></span></td>
                                <td class="entity-id"><?= htmlspecialchars($r['entity_id']) ?></td>
                                <td><?= htmlspecialchars($r['changes']) ?></td>

                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>

<script>
$(document).ready(function () {
    if ($('#sensorDataTable').length) {
        $('#sensorDataTable').DataTable({
            dom: '<"dt-top"Bf>rt<"dt-bottom"lip>',
            buttons: [
                { extend: 'copy',   className: 'dt-btn', exportOptions: { columns: ':not(:first-child)' } },
                { extend: 'csv',    className: 'dt-btn', exportOptions: { columns: ':not(:first-child)' } },
                { extend: 'excel',  className: 'dt-btn', exportOptions: { columns: ':not(:first-child)' } },
                { extend: 'pdf',    className: 'dt-btn', exportOptions: { columns: ':not(:first-child)' } },
                { extend: 'print',  className: 'dt-btn', exportOptions: { columns: ':not(:first-child)' } },
                { extend: 'colvis', className: 'dt-btn', text: 'Kolom' }
            ],
            columnDefs: [
                {
                    targets: 0,
                    orderable: false,
                    searchable: false,
                    className: 'row-num',
                }
            ],
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100, 500],
            order: [],
            language: {
                search:         'Cari:',
                lengthMenu:     'Tampilkan _MENU_ data',
                info:           'Menampilkan _START_ – _END_ dari _TOTAL_ data',
                infoEmpty:      'Tidak ada data',
                paginate: {
                    first:    '«',
                    last:     '»',
                    previous: '‹',
                    next:     '›'
                }
            }
        });
    }
});
</script>

<?php

function sensor_icon(string $name): string {
    $icons = [
        'leaf' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8C8 10 5.9 16.17 3.82 19.57a1 1 0 001.71 1.05L21 3a30.67 30.67 0 01-4 5"></path></svg>',
        'thermometer' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z"></path></svg>',
        'droplet' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"></path></svg>',
        'cloud-drizzle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="19" x2="8" y2="21"></line><line x1="8" y1="13" x2="8" y2="15"></line><line x1="16" y1="19" x2="16" y2="21"></line><line x1="16" y1="13" x2="16" y2="15"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="12" y1="15" x2="12" y2="17"></line><path d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 15.25"></path></svg>',
        'activity' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        'sun' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>',
        'flask' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6m-6 0v6l-7 11a1 1 0 00.87 1.5h14.26A1 1 0 0024 20L17 9V3m-6 0h6"></path></svg>',
        'droplets' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"></path><path d="M12.56 6.6A10.97 10.97 0 0014 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 01-11.91 4.97"></path></svg>',
        'zap' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
        'beaker' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3h15M6 3v10l-3.5 6.5A1 1 0 003.37 21h17.26a1 1 0 00.87-1.5L18 13V3"></path><line x1="6" y1="14" x2="18" y2="14"></line></svg>',
        'radio' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"></circle><path d="M16.24 7.76a6 6 0 010 8.49"></path><path d="M7.76 7.76a6 6 0 000 8.49"></path><path d="M20.49 4.51a12 12 0 010 16.97"></path><path d="M3.51 4.51a12 12 0 000 16.97"></path></svg>',
        'database' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>',
    ];
    return $icons[$name] ?? $icons['database'];
}
