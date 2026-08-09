<?php

require 'config.php';

$token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiIyNDcwMDI2NDgzNjQ0NTdiYTE3MWRjMzExNmMwMDE3OSIsImlhdCI6MTc4MTkzMDI1NiwiZXhwIjoyMDk3MjkwMjU2fQ.EG1i1vZYRIlVgylGjMC44bwfKI_bEkkqdvA8V7KVfbo";

$url = "http://IP_HOME_ASSISTANT:8123/api/states";

$options = [
    'http' => [
        'header' =>
        "Authorization: Bearer $token\r\n" .
        "Content-Type: application/json\r\n"
    ]
];

$context = stream_context_create($options);

$response = file_get_contents(
    $url,
    false,
    $context
);

$data = json_decode($response, true);

foreach ($data as $item) {

    $stmt = $db->prepare("
        INSERT INTO states
        (
            entity_id,
            state,
            last_changed,
            last_updated,
            attributes
        )
        VALUES
        (
            ?,?,?,?,?
        )
    ");

    $stmt->execute([
        $item['entity_id'],
        $item['state'],
        $item['last_changed'],
        $item['last_updated'],
        json_encode($item['attributes'])
    ]);
}

echo "Sync selesai : " . date('Y-m-d H:i:s');