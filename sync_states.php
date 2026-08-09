<?php

require 'config.php';

$token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiIyNDcwMDI2NDgzNjQ0NTdiYTE3MWRjMzExNmMwMDE3OSIsImlhdCI6MTc4MTkzMDI1NiwiZXhwIjoyMDk3MjkwMjU2fQ.EG1i1vZYRIlVgylGjMC44bwfKI_bEkkqdvA8V7KVfbo";

$url = "http://homeassistant.local:8123/api/states";

$options = [
    'http' => [
        'header' =>
        "Authorization: Bearer $token\r\n".
        "Content-Type: application/json\r\n"
    ]
];

$context = stream_context_create($options);

$response = file_get_contents(
    $url,
    false,
    $context
);

if ($response === false) {
    die('Gagal terhubung ke Home Assistant');
}

$data = json_decode($response, true);

if (!is_array($data)) {
    die('Response API tidak valid');
}

$totalSync = 0;

foreach ($data as $item) {

    $entity = $item['entity_id'];

    $stmt = $db->prepare("
        INSERT OR IGNORE INTO states_meta
        (
            entity_id
        )
        VALUES
        (
            ?
        )
    ");

    $stmt->execute([
        $entity
    ]);

    $stmt = $db->prepare("
        SELECT metadata_id
        FROM states_meta
        WHERE entity_id = ?
    ");

    $stmt->execute([
        $entity
    ]);

    $metadata_id = $stmt->fetchColumn();

    $attr = json_encode(
        $item['attributes'],
        JSON_UNESCAPED_UNICODE
    );

    $stmt = $db->prepare("
        INSERT INTO state_attributes
        (
            shared_attrs
        )
        VALUES
        (
            ?
        )
    ");

    $stmt->execute([
        $attr
    ]);

    $attributes_id = $db->lastInsertId();

    $lastChanged = !empty($item['last_changed'])
        ? strtotime($item['last_changed'])
        : null;

    $lastUpdated = !empty($item['last_updated'])
        ? strtotime($item['last_updated'])
        : null;

    $stmt = $db->prepare("
        INSERT INTO states
        (
            metadata_id,
            attributes_id,
            state,
            last_changed_ts,
            last_updated_ts
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $metadata_id,
        $attributes_id,
        $item['state'],
        $lastChanged,
        $lastUpdated
    ]);

    $totalSync++;
}

$stmt = $db->prepare("
INSERT INTO sync_logs
(
    total_entity,
    status
)
VALUES
(
    ?,
    ?
)
");

$stmt->execute([
    $totalSync,
    'Success'
]);
echo "Log berhasil disimpan<br>";

echo "Sync berhasil. Total entity tersimpan: " . $totalSync;