<?php

require 'config.php';
require 'sensor_config.php';

$currentPage   = $_GET['page']   ?? 'sensor';
$currentSensor = $_GET['sensor'] ?? '';

$sections = [];
foreach ($sensorMenus as $key => $cfg) {
    $sec = $cfg['section'] ?? 'Lainnya';
    $sections[$sec][$key] = $cfg;
}

?>

<div class="sidebar">

    <div class="sidebar-brand">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            style="flex-shrink:0;">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        <span>Home Assistant</span>
    </div>

    <ul>

        <!-- live api -->
        <li class="sidebar-divider">
            <span>Live</span>
        </li>

        <li>
            <a href="?page=live"
               class="nav-item <?= ($currentPage === 'live') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="nav-icon">
                    <circle cx="12" cy="12" r="2"></circle>
                    <path d="M16.24 7.76a6 6 0 0 1 0 8.49"></path>
                    <path d="M7.76 7.76a6 6 0 0 0 0 8.49"></path>
                    <path d="M20.49 4.51a12 12 0 0 1 0 16.97"></path>
                    <path d="M3.51 4.51a12 12 0 0 0 0 16.97"></path>
                </svg>
                API
                <span class="live-dot"></span>
            </a>
        </li>

        <?php foreach ($sections as $sectionName => $items): ?>

            <li class="sidebar-divider">
                <span><?= htmlspecialchars($sectionName) ?></span>
            </li>

            <?php foreach ($items as $key => $cfg): ?>

                <li>
                    <a href="?page=sensor&sensor=<?= urlencode($key) ?>"
                       class="nav-item <?= ($currentPage === 'sensor' && $currentSensor === $key) ? 'active' : '' ?>">
                        <span class="nav-icon"><?= sensor_icon_sidebar($cfg['icon'] ?? 'database') ?></span>
                        <?= htmlspecialchars($cfg['label']) ?>
                    </a>
                </li>

            <?php endforeach; ?>

        <?php endforeach; ?>


    </ul>

</div>

<?php

function sensor_icon_sidebar(string $name): string {
    $icons = [
        'leaf'         => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8C8 10 5.9 16.17 3.82 19.57a1 1 0 001.71 1.05L21 3a30.67 30.67 0 01-4 5"></path></svg>',
        'thermometer'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z"></path></svg>',
        'droplet'      => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"></path></svg>',
        'cloud-drizzle'=> '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="19" x2="8" y2="21"></line><line x1="8" y1="13" x2="8" y2="15"></line><line x1="16" y1="19" x2="16" y2="21"></line><line x1="16" y1="13" x2="16" y2="15"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="12" y1="15" x2="12" y2="17"></line><path d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 15.25"></path></svg>',
        'activity'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        'sun'          => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>',
        'flask'        => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6m-6 0v6l-7 11a1 1 0 00.87 1.5h14.26A1 1 0 0024 20L17 9V3m-6 0h6"></path></svg>',
        'droplets'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"></path><path d="M12.56 6.6A10.97 10.97 0 0014 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 01-11.91 4.97"></path></svg>',
        'zap'          => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
        'beaker'       => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3h15M6 3v10l-3.5 6.5A1 1 0 003.37 21h17.26a1 1 0 00.87-1.5L18 13V3"></path><line x1="6" y1="14" x2="18" y2="14"></line></svg>',
        'radio'        => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"></circle><path d="M16.24 7.76a6 6 0 010 8.49"></path><path d="M7.76 7.76a6 6 0 000 8.49"></path><path d="M20.49 4.51a12 12 0 010 16.97"></path><path d="M3.51 4.51a12 12 0 000 16.97"></path></svg>',
        'database'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>',
    ];
    return $icons[$name] ?? $icons['database'];
}
