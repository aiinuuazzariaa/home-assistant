<?php

require 'config.php';

$table = $_GET['table'] ?? 'states';

$allowedTables = [
    'sensor_mapping', 'sensor_readings', 'sensor_quality',
    'scheduler_logs', 'states', 'states_meta', 'state_attributes',
    'events', 'event_types', 'event_data', 'api_states', 'api_events'
];

if (!in_array($table, $allowedTables, true)) {
    echo '<div class="table-card"><div class="table-header"><h2>Tabel tidak valid atau tidak diizinkan.</h2></div></div>';
    return;
}

// get data tabel
$stmt = $db->prepare('SELECT * FROM "' . $table . '" LIMIT 100');
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="table-card">

    <div class="table-header">
        <h2><?= htmlspecialchars(ucwords(str_replace('_', ' ', $table)), ENT_QUOTES, 'UTF-8') ?></h2>
    </div>

    <div class="table-responsive">

        <table class="modern-table">

            <?php if(count($data)>0): ?>

                <thead>
                    <tr>
                        <?php foreach(array_keys($data[0]) as $col): ?>
<th>
    <?= ucwords(str_replace('_', ' ', $col)) ?>
</th>                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach($data as $row): ?>
                        <tr>

                            <?php
                            $cols = array_keys($row);
                            $i = 0;
                            foreach($row as $col => $value):
                                $colName = strtolower($col);
                                $isTimestamp = preg_match('/(_ts|time_fired|last_changed|last_updated|last_reported|last_reset)/', $colName);
                                $isDatetime  = preg_match('/(^(start|end|created|changed)$)/', $colName);
                            ?>

                                <td>

                                    <?php

                                    // format timestamp
                                    if($isTimestamp && $value !== null && $value !== '') {
                                        $dt = DateTime::createFromFormat('U.u', number_format((float)$value, 6, '.', ''));
                                        if (!$dt) $dt = DateTime::createFromFormat('U', (int)$value);
                                        if ($dt) {
                                            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                                            echo "<span class='badge timestamp' title='Unix: " . htmlspecialchars($value) . "'>"
                                                . $dt->format('d/m/Y H:i:s')
                                                . "</span>";
                                        } else {
                                            echo htmlspecialchars($value);
                                        }
                                    }
                                    // format datetime
                                    elseif($isDatetime && $value !== null && $value !== '') {
                                        try {
                                            $dt = new DateTime($value, new DateTimeZone('UTC'));
                                            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                                            echo "<span class='badge timestamp' title='" . htmlspecialchars($value) . "'>"
                                                . $dt->format('d/m/Y H:i:s')
                                                . "</span>";
                                        } catch(Exception $e) {
                                            echo htmlspecialchars($value);
                                        }
                                    }
                                    elseif($value === 'on'){
                                        echo "<span class='badge success'>ON</span>";
                                    }
                                    elseif($value === 'off'){
                                        echo "<span class='badge danger'>OFF</span>";
                                    }
                                    else{
                                        echo htmlspecialchars($value ?? '');
                                    }

                                    ?>

                                </td>

                            <?php endforeach; ?>

                        </tr>
                    <?php endforeach; ?>
                </tbody>

            <?php else: ?>

                <tr>
                    <td>Tidak ada data.</td>
                </tr>

            <?php endif; ?>

        </table>

    </div>

</div>