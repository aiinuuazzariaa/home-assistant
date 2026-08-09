<?php

function run_test($scenario, $date_str) {
    $base_time = strtotime($date_str);

    // Standard -1 day calculation
    $target_time = strtotime('-1 day', $base_time);

    $yesterday = date('Y-m-d\T00:00:00\Z', $target_time);
    $today_time = strtotime('+1 day', $target_time);
    $today_start = date('Y-m-d\T00:00:00\Z', $today_time);
    $today_end = date('Y-m-d\T23:59:59\Z', $today_time);

    echo "Scenario: $scenario ($date_str)\n";
    echo "  Base Time: " . date('Y-m-d H:i:s', $base_time) . "\n";
    echo "  Target Time (Yesterday): " . date('Y-m-d H:i:s', $target_time) . "\n";
    echo "  target_date (start): $yesterday\n";
    echo "  target_date (end): $today_start\n";
    echo "----------------------------------------\n";
}

run_test("Normal day", "2026-08-07");
run_test("1st of August (prev July=31 days)", "2026-08-01");
run_test("1st of July (prev June=30 days)", "2026-07-01");
run_test("1st of March 2026 (prev Feb=28 days)", "2026-03-01");
run_test("1st of March 2024 (prev Feb=29 days)", "2024-03-01");
