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
            // live api
            include 'api.php';
        } elseif ($page === 'db') {
            // tabel api
            $table = $_GET['table'] ?? '';
            if ($table) {
                include 'table.php';
            } else {
                include 'sensor_view.php';
            }
        } else {
            // sensor
            include 'sensor_view.php';
        }

        ?>

    </div>

    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js')
        .then(reg => {
            console.log('Service Worker Registered');

            // schedule check
            const hour = 20;
            const minutes = 3;
            const interval = 1000;

            // backup trigger scheduler
            setInterval(() => {
                const now = new Date();
                if (now.getHours() === hour && now.getMinutes() === minutes) {
                    const runKey = now.toDateString() + '_' + now.getHours() + '_' + now.getMinutes();
                    if (localStorage.getItem('last_scheduler_run') !== runKey) {
                        localStorage.setItem('last_scheduler_run', runKey);
                        console.log('Client: Triggering scheduler');
                        if (reg.active) {
                            reg.active.postMessage({ action: 'triggerSync' });
                        } else {
                            fetch('scheduler.php')
                                .then(r => r.json())
                                .then(d => console.log('Client sync done:', d))
                                .catch(e => console.error(e));
                        }
                    }
                }
            }, interval);
        })
        .catch(err => console.error('Service Worker registration failed:', err));
    }
    </script>
</body>

</html>