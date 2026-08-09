<?php
$db = new PDO('sqlite:C:/laragon/www/home-assistant/database/home_assistant.db');
$stmt = $db->query('SELECT COUNT(*) FROM api_history');
echo 'count=' . $stmt->fetchColumn() . "\n";
$stmt = $db->query('SELECT * FROM api_history LIMIT 3');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
