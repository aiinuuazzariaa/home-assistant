// Service Worker for Home Assistant Scheduler
const CACHE_NAME = 'ha-scheduler-cache-v1';
const ASSETS = [
  './',
  './index.php',
  './assets/css/style.css'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());

  // Set up checking interval inside SW
  // Note: SW may be terminated when idle, so client-side registrar also runs a scheduler backup.
  setupScheduler();
});

let schedulerInterval = null;

function setupScheduler() {
  if (schedulerInterval) clearInterval(schedulerInterval);

  schedulerInterval = setInterval(() => {
    const now = new Date();
    const hours = now.getHours();
    const minutes = now.getMinutes();

    // Trigger at 00:01
    if (hours === 0 && minutes === 1) {
      console.log('SW: Triggering scheduler at 00:01');
      triggerSync();
    }
  }, 60000); // Check every minute
}

async function triggerSync() {
  try {
    const response = await fetch('scheduler.php');
    const result = await response.json();
    console.log('SW: Scheduler sync response:', result);
  } catch (error) {
    console.error('SW: Scheduler sync failed:', error);
  }
}

// Support message triggers from client
self.addEventListener('message', (event) => {
  if (event.data && event.data.action === 'triggerSync') {
    event.waitUntil(triggerSync());
  }
});
