<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Assistant DB</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">

        <?php

        $page = $_GET['page'] ?? 'sensor';

        if ($page === 'live') {
            // live api — data real-time dari Home Assistant
            include 'api.php';

        } elseif ($page === 'quality') {
            // data quality assessment — completeness, validity, consistency, timeliness
            include 'quality_view.php';

        } elseif ($page === 'mapping') {
            // sensor mapping — standardisasi entitas sensor
            include 'mapping_view.php';

        } elseif ($page === 'db') {
            // raw db explorer
            $table = $_GET['table'] ?? '';
            if ($table) {
                include 'table.php';
            } else {
                include 'sensor_view.php';
            }

        } else {
            // sensor data historis
            include 'sensor_view.php';
        }

        ?>

    </div>

    <script>
    // HA Recorder aktif - data tersimpan langsung ke PostgreSQL secara real-time dari Home Assistant
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js')
            .then(reg => console.log('Service Worker Registered'))
            .catch(err => console.error('Service Worker registration failed:', err));
    }
    </script>
</body>

</html>