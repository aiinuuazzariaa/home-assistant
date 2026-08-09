# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP web dashboard for viewing and syncing Home Assistant data. It connects to:
- A **live Home Assistant REST API** (`http://homeassistant.local:8123`) for real-time entity states
- A **local PostgreSQL database** (`home_assistant` on port 5432) that mirrors HA data for offline access and historical querying

The app runs under **Laragon** at `http://localhost/home-assistant/`.

## Architecture

All routing is handled by `index.php` via a `?page=` GET parameter:

| `?page=` | File included | Purpose |
|---|---|---|
| `live` | `api.php` | Fetches live states from HA REST API; falls back to `api_states` table if offline |
| `sensor` | `sensor_view.php` | Queries historical sensor/event data from the relational PostgreSQL tables |
| `db` + `?table=` | `table.php` | Generic raw table browser (first 100 rows of any table) |

**Config files:**
- `config.php` — PostgreSQL PDO connection (exposes `$db`)
- `config_api.php` — Home Assistant URL (`$ha_url`) and Bearer token (`$ha_token`)

**Sensor system (`sensor_config.php` + `sensor_view.php`):**  
`sensor_config.php` defines `$sensorMenus` — an associative array where each key is a menu slug and each value is a config array describing `type`, `filter`, `columns`, `icon`, etc. `sensor_view.php` reads the `?sensor=` param, looks up the config, then dispatches to one of six query types: `akar`, `sensor`, `kandungan`, `event`, `event_callservice`, `event_change`. The sidebar (`sidebar.php`) auto-generates nav links grouped by `section` from the same config.

**Relational DB schema (mirrored from HA):**
```
states_meta     (metadata_id, entity_id)
state_attributes (attributes_id, shared_attrs JSON)
states          (state_id, metadata_id→, attributes_id→, state, last_changed_ts, last_updated_ts, last_reported_ts)
events          (event_id, event_type_id→, data_id→, time_fired_ts)
event_types     (event_type_id, event_type)
event_data      (data_id, hash, shared_data JSON)
```
Timestamps in `states` are Unix epoch floats. Sensor queries join these three tables and use `ILIKE` for attribute JSON filtering.

**Scheduler (`scheduler.php`):**  
Fetches multiple HA API endpoints (`/api/states`, `/api/history/period/...`, `/api/logbook/...`, `/api/states/<entity_id>`, `/api/error_log`, `/api/camera_proxy/...`, `/api/calendars/...`) and upserts into corresponding `api_*` tables using `sync_to_db()`. Deduplication is timestamp-based (skips rows where `entity_id` + timestamp already exist). Triggered automatically at 00:01 via the Service Worker (`sw.js`), with a client-side backup trigger at 20:55 from `index.php`.

## Running the App

Open `http://localhost/home-assistant/` in a browser (served by Laragon/Apache+PHP). No build step required.

To manually trigger the scheduler sync:
```
http://localhost/home-assistant/scheduler.php
```
Or with a specific date to backfill:
```
http://localhost/home-assistant/scheduler.php?date=2026-08-01
```

## Configuration

**To change the HA connection**, edit `config_api.php`:
- `$ha_url` — base URL of your Home Assistant instance
- `$ha_token` — Long-lived access token from HA profile settings

**To change the PostgreSQL connection**, edit `config.php` (host, port, dbname, user, password).

## Adding a New Sensor Menu

Add an entry to `$sensorMenus` in `sensor_config.php`. The `type` field controls the query path in `sensor_view.php`:
- `sensor` — joins `states`/`state_attributes`/`states_meta`, filters by `shared_attrs ILIKE`
- `kandungan` — same join, but filters by exact `entity_id` and reads a specific attribute field (`field1`–`field7`)
- `akar` — same join, shows entity metadata attributes
- `event` / `event_callservice` / `event_change` — queries `events`/`event_types`/`event_data`

The sidebar and icon rendering pick up new entries automatically. Icons must be one of the named SVG strings in `sensor_icon()` / `sensor_icon_sidebar()` inside `sensor_view.php` and `sidebar.php`.

## Key Conventions

- All timestamps are stored as Unix epoch floats; display converts to `Asia/Jakarta` timezone using `DateTimeZone`.
- `htmlspecialchars()` is used consistently on all output to prevent XSS.
- The `kandungan` type reads soil sensor values from a single `sensor.sensor_smartfarm` entity whose multiple measurements are packed into attributes (`field1`–`field7`).
- `table.php` is a raw debug viewer — it directly interpolates `$_GET['table']` into a SQL query with no sanitization; use only on localhost.
- DataTables (jQuery-based, loaded from CDN) is used in `sensor_view.php` for pagination, search, and CSV/Excel/PDF export.
