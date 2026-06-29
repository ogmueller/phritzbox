# Phritzbox — Architecture Documentation

## Overview

Phritzbox is a self-hosted smart home dashboard for smart devices connected to AVM Fritz!Box. It provides a React web UI and a Symfony REST API that bridge the Fritz!Box AHA (AVM Home Automation) HTTP API, storing time-series telemetry and exposing device control to authenticated users.

**Stack summary:** PHP 8.5 / Symfony 8 · SQLite / Doctrine ORM · JWT authentication · React 19 / Vite · ECharts · Docker (FrankenPHP)

---

## Table of Contents

1. [High-Level Architecture](#1-high-level-architecture)
2. [Backend](#2-backend)
   - [Entry Points & Kernel](#21-entry-points--kernel)
   - [Fritz!Box Client Layer](#22-fritzbox-client-layer)
   - [Domain Model](#23-domain-model)
   - [Database Layer](#24-database-layer)
   - [REST API Controllers](#25-rest-api-controllers)
   - [Console Commands](#26-console-commands)
   - [Security & Authentication](#27-security--authentication)
   - [Backend Dependencies](#28-backend-dependencies)
   - [Alerting & Notifications](#29-alerting--notifications)
3. [Frontend](#3-frontend)
   - [Entry Point & Routing](#31-entry-point--routing)
   - [API Client Layer](#32-api-client-layer)
   - [State Management](#33-state-management)
   - [Layout & Navigation](#34-layout--navigation)
   - [Pages](#35-pages)
   - [Device Components](#36-device-components)
   - [Chart Components](#37-chart-components)
   - [UI Primitives](#38-ui-primitives)
   - [Frontend Dependencies](#39-frontend-dependencies)
4. [Infrastructure & Deployment](#4-infrastructure--deployment)
   - [Docker Setup](#41-docker-setup)
   - [Build Pipeline](#42-build-pipeline)
   - [CI/CD Pipelines](#43-cicd-pipelines)
   - [Environment Variables](#44-environment-variables)
5. [Data Flows](#5-data-flows)
6. [Testing](#6-testing)
7. [Key Architectural Decisions](#7-key-architectural-decisions)

---

## 1. High-Level Architecture

```
Browser
  │
  │  HTTPS / HTTP
  ▼
┌─────────────────────────────────────────────────────┐
│  FrankenPHP / Caddy (port 80)                       │
│  · Serves /frontend/assets/* with long-term cache   │
│  · Routes PHP requests to embedded PHP runtime      │
│  · Serves index.html for all other paths (SPA)      │
│  · Security headers (CSP, X-Frame-Options, etc.)    │
├─────────────────────────────────────────────────────┤
│  Symfony 8 (embedded in FrankenPHP)                 │
│  · JWT firewall (stateless)                         │
│  · REST API: /api/devices, /api/stats, /api/users   │
│  · Console commands (cron:smart:savestats, etc.)    │
└───────┬─────────────────────────┬───────────────────┘
        │ Doctrine ORM            │ HTTP (NativeHttpClient)
        ▼                         ▼
  SQLite database           Fritz!Box router
  (smart_device_data,       AHA HTTP API
   user)                    /login_sid.lua
                            /webservices/homeautoswitch.lua
```

FrankenPHP replaces the traditional Nginx + PHP-FPM stack with a single process that embeds both the web server (Caddy) and the PHP runtime. The React SPA is compiled into `app/public/frontend/` and served as static files by Caddy. It calls back to `GET /api/*` endpoints on the same origin, so no CORS issues arise in production. In development the Vite dev server proxies `/api` to the FrankenPHP server on port 80.

---

## 2. Backend

### 2.1 Entry Points & Kernel

| File | Role |
|------|------|
| `app/public/index.php` | Web entry point; bootstraps Symfony Runtime |
| `app/bin/console` | CLI entry point for all console commands |
| `app/src/Kernel.php` | Extends `BaseKernel` + `MicroKernelTrait`; overrides `getCacheDir` and `getLogDir` to `../var/` (outside the `app/` directory, keeping it out of Docker image builds) |

### 2.2 Fritz!Box Client Layer

Located in `app/src/Client/`.

#### `AhaApi`

The single facade for all Fritz!Box communication. It translates method calls into AHA HTTP commands and parses the responses.

| Method group | Fritz!Box command | Returns |
|---|---|---|
| `getSwitchList()` | `getswitchlist` | `string[]` of AIns |
| `setSwitchOn/Off/Toggle(ain)` | `setswitchon/off/toggle` | void |
| `getSwitchPower/Energy/Name/Present(ain)` | `getswitchpower` etc. | scalar |
| `getDeviceListInfos()` | `getdevicelistinfos` | `Device[]` (parses XML) |
| `getTemperature(ain)` | `gettemperature` | float (°C) |
| `getSrc*/setSrc*(ain)` | `gethkrtsoll/sethkrtsoll` | float / void |
| `getBasicDeviceStats(ain)` | `getbasicdevicestats` | nested array of time-series data |
| `getTemplateListInfos()` | `gettemplatelistinfos` | array |

The thermostat setpoint is clamped to 8–28 °C and mapped to the raw 0–56 range that the AHA API uses (multiply by 2).

#### `Helper`

Handles the low-level concerns that `AhaApi` delegates to:

- **Authentication** — Fritz!Box uses a SID-based challenge-response login. `getSid()` fetches `/login_sid.lua`, reads the challenge, computes `MD5(challenge + "-" + UTF-16LE(password))`, and POSTs it. The resulting SID is cached in `cache.app` for 15 minutes so repeated CLI commands avoid redundant logins.
- **HTTP transport** — Uses Symfony's `NativeHttpClient` (PHP streams, not cURL). This is deliberate: Fritz!Box routers send a TLS `close_notify` alert that confuses cURL, while the native PHP stream wrapper handles it correctly.
- **Unit conversion** — `bestFactor()` converts raw milli-values to human-readable units with SI prefixes.

#### `InvalidResponseException`

A `RuntimeException` subclass thrown when the Fritz!Box API returns an unexpected response (e.g., empty SID on login failure).

---

### 2.3 Domain Model

Located in `app/src/` and `app/src/Device/Feature/`.

#### `Device`

Value object representing a single Fritz!Box device, populated from the XML returned by `getdevicelistinfos`.

```
Device
├── identifier (AIN)
├── id, name, present, firmwareVersion, manufacturer, productName
├── functionBitMask  ← bitmask of capabilities
└── featureList[]    ← Feature objects instantiated from bitmask
```

**Capability bitmask constants:**

| Constant | Bit | Meaning |
|---|---|---|
| `FUNCTION_BIT_THERMOSTAT` | 6 | Smart radiator control (SRC/HKR) |
| `FUNCTION_BIT_POWER_METER` | 7 | Power & energy monitoring |
| `FUNCTION_BIT_TEMPERATURE_SENSOR` | 8 | Temperature reading |
| `FUNCTION_BIT_OUTLET` | 9 | Switchable outlet |
| `FUNCTION_BIT_ALARM` | 4 | Door/window contact alarm |
| `FUNCTION_BIT_MICROFON` | 11 | Microphone |

The `xmlFactory(SimpleXMLElement)` static method is the canonical way to construct a `Device` — it reads the XML node and auto-instantiates the applicable `Feature` objects.

#### `Feature` (abstract base, `app/src/Device/Feature.php`)

Defines the contract for all feature types: `setXml(SimpleXMLElement)` and `toArray()`. Each feature reads a specific XML sub-node (`<switch>`, `<powermeter>`, `<temperature>`, `<hkr>`).

| Concrete class | XML node | Properties |
|---|---|---|
| `Feature\Outlet` | `<switch>` | `switchState`, `switchMode`, `switchLock`, `switchDeviceLock` |
| `Feature\PowerMeter` | `<powermeter>` | `powerMeterVoltage` (V), `powerMeterPower` (W), `powerMeterEnergy` (Wh) — all divided by 1000 from raw milli-values |
| `Feature\Temperature` | `<temperature>` | `temperatureCelsius`, `temperatureOffset` — divided by 10 from raw deci-degrees |

---

### 2.4 Database Layer

#### Entities

**`User`** (`app/src/Entity/User.php`)

Implements Symfony's `UserInterface` and `PasswordAuthenticatedUserInterface`. Fields: `id`, `username` (unique), `email` (unique), `roles` (JSON array), `password` (hashed), `createdAt`.

**`SmartDeviceData`** (`app/src/Entity/SmartDeviceData.php`)

Flat time-series table. No foreign keys — deliberately denormalised for simplicity at home-automation scale.

| Column | Type | Description |
|---|---|---|
| `dataId` | INTEGER PK | Auto-increment |
| `sid` | VARCHAR(255) | AIN of the device (e.g., `"12345 6789012"`) |
| `type` | VARCHAR(255) | Metric: `temperature`, `power`, `energy`, `voltage` |
| `time` | DATETIME | Timestamp of reading |
| `value` | FLOAT | Numeric value |

A **UNIQUE index on `(sid, type, time)`** enforces one reading per device/metric/timestamp (it also serves the range queries). It replaced an earlier non-unique index after overlapping collection runs were found to have inserted duplicate rows; collection now uses `INSERT OR IGNORE` so duplicates can't recur.

**`SmartDevice`** (`app/src/Entity/SmartDevice.php`)

Cached device metadata (name, manufacturer, product, firmware, feature bitmask) keyed by `ain`, upserted on each collection so the UI/alerts can resolve a device name without a live Fritz!Box call.

**`AlertRule`** (`app/src/Entity/AlertRule.php`)

A user-defined alert. Fields: `id`, `name`, `enabled`, `mode` (`threshold` | `comparison`), `sid` + `type` (subject device/metric), `operator` (`gt`/`lt`/`gte`/`lte`), `threshold` (threshold mode), `compareSid`/`compareType`/`compareOffset` (comparison mode), `durationMinutes` (0 = latest reading; >0 = sustained), `cooldownMinutes` (0 = alert once, >0 = reminder interval), `lastState`/`lastTriggeredAt`/`lastNotifiedAt` (edge-trigger state), `createdAt`. Has a **many-to-many** relation to `NotificationChannel` (join table `alert_rule_channel`) — a rule notifies one or more channels.

**`NotificationChannel`** (`app/src/Entity/NotificationChannel.php`)

A reusable delivery destination. Fields: `id`, `name`, `type` (`email`, `webhook`, `pushover`, `telegram`, `ntfy`, `discord`, `gotify`, `slack`), `target` (address/URL/chat-id/user-key per type), `secret` (nullable token, e.g. Pushover/Telegram/Gotify), `enabled`, `createdAt`.

**`AlertEvent`** (`app/src/Entity/AlertEvent.php`)

Append-only audit log of alert lifecycle events, powering the Alerts "Recent activity" view. Fields: `id`, `ruleId` + `ruleName` (denormalised so the history survives rule deletion), `state` (`triggered` | `resolved` | `rearmed`), `type`, `valueDisplay`/`compareDisplay` (the readings at evaluation time, in display units), `deliveries` (JSON array of `{channel, type, ok, error}` — empty for `resolved`/`rearmed`, which send no message), `createdAt` (indexed for recency queries). Written by `AlertEvaluationService` on trigger/resolve and by `AlertController::rearm()` on a manual re-arm.

**`RefreshToken`** (`app/src/Entity/RefreshToken.php`)

Backs the rotating refresh-token flow that keeps the short-lived JWT access token usable without re-login. Fields include the hashed token value, the owning username, and an expiry; `App\Security\RefreshTokenManager` issues a token on login, rotates it on each `/api/auth/refresh`, and revokes it on `/api/auth/logout`. See §2.7.

**`app_state`** (table only — no entity)

A tiny key/value table (`name`, `value`) for singleton runtime state. The only key today is `last_collection_at`, written by the collection service after a successful run and read back — via raw DBAL, not the ORM — by `HealthController` to compute data staleness (see §2.5). Kept entity-free because it's a single scalar, not a domain object.

#### Repositories

All extend `ServiceEntityRepository`. `SmartDeviceDataRepository` filters by AIN/type/time range; `UserRepository` backs the security provider; `AlertRuleRepository` exposes `findEnabled()` and `countUsingChannel()` (used to block deleting an in-use channel); `AlertEventRepository` exposes `findRecent($limit)` for the activity log; `NotificationChannelRepository` is plain CRUD.

#### Migrations

`Version20260411024338` creates the `user` table and seeds a default `admin` / `admin` account (bcrypt cost 12 — **change after first deployment**). Later migrations add: the `smart_device` cache table; the `(sid, type, time)` index on `smart_device_data` (critical — turns report queries from full scans into index seeks on multi-million-row datasets); the `notification_channel` + `alert_rule` tables; the `alert_rule_channel` join table (the rule→channel relation evolved from a single FK to many-to-many, migrating existing links in place); the `alert_event` log table; a migration that de-duplicates `smart_device_data` and upgrades the index to **UNIQUE** `(sid, type, time)`; the `refresh_token` table; and the `app_state` key/value table (seeding `last_collection_at`).

---

### 2.5 REST API Controllers

All live under `app/src/Controller/Api/` and use PHP 8 `#[Route]` attributes.

#### `DeviceController` — `/api/devices`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/devices` | ROLE_USER | List all devices |
| GET | `/api/devices/{ain}` | ROLE_USER | Get single device by AIN |
| POST | `/api/devices/{ain}/on` | ROLE_USER | Turn outlet on |
| POST | `/api/devices/{ain}/off` | ROLE_USER | Turn outlet off |
| PUT | `/api/devices/{ain}/setpoint` | ROLE_USER | Set thermostat target (JSON `{temperature}`) |

Responses are hand-serialised into nested JSON mirroring the `Device` / `Feature` structure, since the Feature objects are not Doctrine entities and aren't registered with Symfony Serializer.

#### `StatsController` — `/api/stats`

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/stats/refresh` | ROLE_USER | On-demand collection from the Fritz!Box (`SmartStatsCollectionService::collectAll()`), then an immediate `AlertEvaluationService::evaluateAll()` so a manual pull also checks alert rules. Backs the Reports "Pull latest data" button |
| GET | `/api/stats/alert-events` | ROLE_USER | Alert firings in a window, for the Reports chart markers; query params: `type`, `devices` (CSV of AINs), `from`/`to`. Available to any authenticated user, unlike the admin-only alert *config* |
| GET | `/api/stats/{ain}` | ROLE_USER | Query time-series data; query params: `type`, `from` (default: `-24 hours`), `to` (default: `now`). Collapses duplicate timestamps defensively |
| GET | `/api/stats/types/{ain}` | ROLE_USER | List available metric types for a device |

#### `HealthController` — `/api/health`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/health` | ROLE_USER | Reports data freshness: `{lastCollectedAt, ageMinutes}`, read from the `app_state` table via raw DBAL. The frontend `StaleDataBanner` polls this and warns when collection has stalled (e.g. the host slept and a scheduled run was skipped) |

#### `UserController` — `/api/users`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/users` | ROLE_ADMIN | List all users |
| POST | `/api/users` | ROLE_ADMIN | Create user (JSON: `username`, `email`, `password`, `roles`) |
| PUT | `/api/users/{id}` | ROLE_ADMIN | Update user (partial) |
| DELETE | `/api/users/{id}` | ROLE_ADMIN | Delete user (self-deletion blocked with 403) |
| PUT | `/api/users/me/password` | ROLE_USER | Change own password (JSON: `currentPassword`, `newPassword`) |

Role validation restricts assignable roles to `ROLE_USER` and `ROLE_ADMIN` — unknown roles return 400. Password hashing uses Symfony's `UserPasswordHasherInterface` (bcrypt by default).

#### `AlertController` — `/api/alerts`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/alerts` | ROLE_ADMIN | List alert rules |
| GET | `/api/alerts/events` | ROLE_ADMIN | Recent activity log (`?limit=`, default 50) |
| POST | `/api/alerts` | ROLE_ADMIN | Create rule (JSON incl. `channelIds[]`) |
| PUT | `/api/alerts/{id}` | ROLE_ADMIN | Update rule |
| DELETE | `/api/alerts/{id}` | ROLE_ADMIN | Delete rule |
| POST | `/api/alerts/{id}/toggle` | ROLE_ADMIN | Flip `enabled` |
| POST | `/api/alerts/{id}/rearm` | ROLE_ADMIN | Reset a latched rule to OK so it can fire again (logs a `rearmed` event) |
| POST | `/api/alerts/{id}/test` | ROLE_ADMIN | Send a test notification through the rule's channels |

#### `ChannelController` — `/api/channels`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/channels` | ROLE_ADMIN | List channels |
| POST | `/api/channels` | ROLE_ADMIN | Create channel (per-type validation of `target`/`secret`) |
| PUT | `/api/channels/{id}` | ROLE_ADMIN | Update channel |
| DELETE | `/api/channels/{id}` | ROLE_ADMIN | Delete channel (409 if still referenced by a rule) |

#### `FrontendController`

Catch-all for non-API routes. Serves `app/public/frontend/index.html` so React Router handles client-side navigation. Returns a plain-text fallback if the frontend has not been built yet.

---

### 2.6 Console Commands

All commands extend the abstract `Smart` base class in `app/src/Command/`.

#### `Smart` (base class)

Provides shared concerns so subclasses stay focused:

- Prompts for AIN selection (lists all devices with their features)
- Caches the device list for 15 minutes
- Measures and prints execution time via Symfony Stopwatch
- Defines the template method `executeSmart(input, output, errOutput, stopwatch)` that subclasses implement

#### Command inventory

| Command | Class | Description |
|---|---|---|
| `cron:smart:savestats` | `CronSmartSaveStats` | **Primary data collection entry point.** Fetches stats for all devices (concurrently, bounded), finds the shortest interval (highest resolution), stores new datapoints in `smart_device_data`, skipping rows already present for a timestamp+type. Designed to run every 5–30 minutes via cron. Delegates to `SmartStatsCollectionService` so the same logic backs the web "pull latest data" button. |
| `cron:smart:alerts` | `CronEvaluateAlerts` | Evaluates enabled alert rules and dispatches notifications (via `AlertEvaluationService`). A plain `Command` (no Fritz!Box access). Scheduled a few minutes after `savestats` so it sees fresh data. |
| `smart:device:list` | `SmartDeviceList` | Print all devices with feature flags |
| `smart:device:stats` | `SmartDeviceStats` | Show device time-series data as ASCII charts |
| `smart:switch:list` | `SmartSwitchList` | List all switchable outlets |
| `smart:switch:on/off/toggle` | `SmartSwitch{On,Off,Toggle}` | Control outlet power state |
| `smart:switch:power/energy/name/present` | `SmartSwitch*` | Query outlet metrics |
| `smart:src:on/off/setpoint/comfort/saving` | `SmartSrc*` | Control smart radiator controller |
| `smart:temperature` | `SmartTemperature` | Read temperature sensor |
| `smart:template:list` | `SmartTemplateList` | List Fritz!Box automation templates |

---

### 2.7 Security & Authentication

**JWT (RS256)** via `lexik/jwt-authentication-bundle`.

Login flow:
1. `POST /api/auth/login` with JSON `{username, password}` — handled by Symfony's built-in JSON login authenticator
2. On success, the Lexik bundle generates a JWT signed with the RS256 private key
3. The access token (plus a rotating refresh token) is returned in the response body
4. All subsequent API calls include `Authorization: Bearer <token>`
5. The `api` firewall validates the signature against the public key on every request (stateless — no session)

**Short-lived access tokens + rotating refresh tokens.** `token_ttl` is set to **3600 s** in
`config/packages/lexik_jwt_authentication.yaml` — deliberately short. `App\Security\RefreshTokenManager`
issues a refresh token (persisted as a `RefreshToken` entity) on login and rotates it on each
`POST /api/auth/refresh`; the SPA's `client.ts` silently renews on a 401 and replays the request, so an
expired or leaked access token is only briefly useful. `POST /api/auth/logout` revokes the refresh token.

Access control matrix:

| Path | Required role |
|---|---|
| `/api/auth/login` | PUBLIC_ACCESS |
| `/api/auth/refresh` | PUBLIC_ACCESS |
| `/api/auth/logout` | PUBLIC_ACCESS |
| `/api/users/**` | ROLE_ADMIN |
| `/api/alerts/**` | ROLE_ADMIN |
| `/api/channels/**` | ROLE_ADMIN |
| `/api/**` (incl. `/api/health`, `/api/stats/**`) | ROLE_USER |
| `/**` (frontend) | none |

---

### 2.8 Backend Dependencies

#### Production

| Package | Why this, not an alternative |
|---|---|
| `symfony/framework-bundle` ^8 | Full-featured PHP framework with DI, routing, event system. Alternative: Laravel — Symfony is preferred for API-first apps and CLI tools due to its composability. |
| `symfony/security-bundle` | Pluggable authentication; integrates with Lexik JWT out of the box. |
| `symfony/http-client` | Used in `Helper`; the `NativeHttpClient` adapter avoids the cURL TLS `close_notify` bug with Fritz!Box. Alternative: Guzzle — heavier dependency for a narrow use case. |
| `symfony/console` | First-class CLI support with argument/option parsing, coloured output, progress bars. |
| `symfony/cache` | Caches the Fritz!Box SID (15 min). Uses filesystem adapter with no external cache server needed. |
| `doctrine/orm` ^3 | Full ORM with Migrations. Alternative: DBAL only — ORM chosen for the entity abstraction and migration tooling, acceptable overhead for two small tables. |
| `doctrine/doctrine-migrations-bundle` | Schema versioning. Alternative: manual SQL scripts — migrations give repeatable, reviewable schema history. |
| `lexik/jwt-authentication-bundle` | Mature JWT library for Symfony with straightforward Symfony Security integration. Alternative: custom token implementation — no reason to reinvent this. |
| `nelmio/cors-bundle` | Handles CORS preflight for the dev Vite proxy. Unnecessary in production (same origin) but harmless. |
| `symfony/mailer` | Sends e-mail alerts. Default `MAILER_DSN=null://null` discards mail until an SMTP DSN is configured; webhook/push channels need no mail server. |
| `ext-simplexml` | Fritz!Box XML API responses are parsed with SimpleXML. Alternative: DOMDocument — SimpleXML is lighter for read-only XML traversal. |
| `noximo/php-colored-ascii-linechart` | ASCII charts for `smart:device:stats` CLI output. No comparable Composer alternative for terminal line charts. |

#### Development

| Package | Purpose |
|---|---|
| `phpunit/phpunit` ^13 | Unit and integration testing |
| `symfony/phpunit-bridge` | Symfony-aware test utilities (deprecation handler, etc.) |
| `dama/doctrine-test-bundle` | Wraps each test in a transaction and rolls back, giving fast isolated DB tests without truncating tables |
| `friendsofphp/php-cs-fixer` | Enforces PSR-12 + custom rules; configured in `.php-cs-fixer.dist.php` |
| `phpstan/phpstan` (+ `phpstan-symfony`, `phpstan-doctrine`) | Static type analysis at **level 6**; config in `phpstan.dist.neon`, run via `composer phpstan` |
| `phpmd/phpmd` | Static analysis for code smells |
| `roave/security-advisories` | Blocks installation of packages with known CVEs |

---

### 2.9 Alerting & Notifications

A rule-based alerting subsystem that watches collected readings and notifies the user. Example use case: pair an outdoor and an indoor temperature sensor and create a comparison rule (*outdoor < indoor − 2 °C*) to know when to open the windows — smart temperature management for the home.

**Domain.** `AlertRule` (the condition) ↔ `NotificationChannel` (the destination), many-to-many. Two rule modes:
- **threshold** — `value <operator> threshold`, optionally **sustained** for `durationMinutes` (every sample in the window must satisfy it, via a single indexed `MIN/MAX/COUNT` query).
- **comparison** — `A <operator> B + offset`, where A and B are the latest readings of two devices for the same metric.

**Units.** `App\Service\MetricUnits` is the single source of truth for stored↔display conversion (voltage mV→V, power cW→W; temperature/energy 1:1). Thresholds/offsets are entered in display units and converted to stored units once, so comparisons run directly against raw DB values.

**Evaluation** (`App\Service\AlertEvaluationService::evaluateAll()`):
1. Load enabled rules; for each, compute "triggered?" using fast `idx_sid_type_time` queries (latest value, or windowed aggregate for sustained).
2. **Edge-triggered state machine:** notify on the `ok → triggered` transition; while it stays triggered, re-notify only if `cooldownMinutes > 0` and the interval elapsed (`0` = alert once). The `triggered → ok` transition is **recorded but not notified** (only the triggering edge sends messages). State persists in `lastState`/`lastTriggeredAt`/`lastNotifiedAt`; an admin can manually re-arm a latched rule via `POST /api/alerts/{id}/rearm`.
3. Build the message once, then dispatch to **every enabled channel** on the rule (per-channel try/catch — one failing channel doesn't block the others or the rule).
4. **Audit log:** every trigger, resolution, and re-arm is persisted as an `AlertEvent` with the readings and per-channel delivery outcome, surfaced in the Alerts "Recent activity" table. This makes an otherwise-silent delivery failure (a channel that threw) visible.

**Delivery.** `App\Notification\AlertChannelInterface` (tagged `app.alert_channel`) is implemented by one transport per type — `EmailAlertChannel`, `WebhookAlertChannel`, `PushoverAlertChannel`, `TelegramAlertChannel`, `NtfyAlertChannel`, `DiscordAlertChannel`, `GotifyAlertChannel`, `SlackAlertChannel`. `AlertNotifier` routes a `NotificationChannel` to the transport whose `supports($type)` matches; transports POST via `HttpClientInterface` (or `MailerInterface` for e-mail) and throw on non-2xx so failures surface in the `/test` endpoint and the cron log. Adding a new channel type = one class + a constant in `NotificationChannel::TYPES`.

**Scheduling.** The `cron:smart:alerts` command runs on its own cronado schedule (`5,35 * * * *`), a few minutes after each `*/30` collection. Alerts therefore reflect stored data and can lag real time by up to the collection interval. The Reports "Pull latest data" endpoint (`POST /api/stats/refresh`) also calls `evaluateAll()` right after collecting, so a manual pull checks the rules immediately rather than waiting for the cron.

---

## 3. Frontend

### 3.1 Entry Point & Routing

**`main.tsx`** — mounts `<App />` into `#root`.

**`App.tsx`** — sets up the full application tree. All page components are lazy-loaded via `React.lazy()` and wrapped in `<Suspense>` for code splitting (ECharts ~800 kB is only loaded when chart pages are visited). An `ErrorBoundary` wraps the entire app to catch rendering errors and display a user-friendly reload prompt instead of a white screen.

```
ErrorBoundary
└── AuthProvider
    ├── NotificationHost        (global error toasts, route-independent)
    └── BrowserRouter
        └── Suspense
            ├── /login              → LoginPage         (lazy, public)
            └── RequireAuth
                └── AppLayout
                    ├── /dashboard          → DashboardPage    (lazy)
                    ├── /devices/:ain       → DeviceDetailPage (lazy)
                    ├── /reports            → ReportsPage      (lazy)
                    ├── /help               → HelpPage         (lazy)
                    └── RequireAdmin
                        ├── /users          → UsersPage        (lazy)
                        ├── /alerts         → AlertsPage       (lazy)
                        └── /channels       → ChannelsPage     (lazy)
```

`RequireAuth` reads from `AuthContext`; if no token is present it redirects to `/login` preserving the intended destination. `RequireAdmin` additionally checks `isAdmin` and redirects non-admin users to `/dashboard`.

---

### 3.2 API Client Layer

Located in `app/frontend/src/api/`.

#### `client.ts`

A thin wrapper over `fetch` — not Axios, not React Query. It exposes `api.get<T>`, `api.post<T>`, `api.put<T>`, and `api.delete<T>`. On every request it:

1. Reads the JWT from `localStorage` under the key `phritzbox_token`
2. Adds `Authorization: Bearer <token>` if a token exists
3. Sets `Content-Type: application/json`
4. On **401** — tries a one-shot silent token refresh and replays the request; only on refresh failure does it clear the token and redirect to `/login`
5. On a **5xx** response or a **network/fetch error** — publishes a global error notification (see below) before throwing, so background loads fail visibly
6. On non-2xx — throws an `Error` with the status code (4xx stays inline on the calling page)
7. On **204** — returns `undefined` (no body)

This keeps all auth-token logic in one place so individual API modules stay clean.

#### `notifications/bus.ts` + `NotificationHost`

`client.ts` is a plain module, so it can't call a React context. A tiny framework-agnostic event bus (`pushNotification` / `subscribe`) bridges the gap: the client publishes serious-error notifications and `components/layout/NotificationHost` (mounted once near the app root) subscribes and renders top-centre toasts — de-duplicating identical messages (with a `×N` count), auto-dismissing after ~10 s, and offering a manual close. App code can also `pushNotification` directly.

#### `auth.ts`

`loginRequest(username, password)` — single function; POSTs credentials to `/api/auth/login` and returns the raw JWT string.

#### `devices.ts`

| Function | HTTP | Description |
|---|---|---|
| `getDevices()` | GET `/api/devices` | Fetch all devices |
| `getDevice(ain)` | GET `/api/devices/{ain}` | Fetch single device |
| `turnOn(ain)` | POST `/api/devices/{ain}/on` | Switch outlet on |
| `turnOff(ain)` | POST `/api/devices/{ain}/off` | Switch outlet off |
| `setSetpoint(ain, celsius)` | PUT `/api/devices/{ain}/setpoint` | Set thermostat target |

TypeScript interfaces `Device`, `DeviceFeatures`, `OutletFeature`, `ThermostatFeature`, `PowerMeterFeature`, `TemperatureFeature` mirror the backend JSON shape exactly.

#### `stats.ts`

`getStats(ain, type?, from?, to?)`, `getStatTypes(ain)`, `refreshStats()` (POST `/api/stats/refresh`), and `getReportAlertEvents(type, from, to, devices[])` (GET `/api/stats/alert-events`, for the Reports chart markers). The `StatsResponse` interface contains `ain`, `type`, and `data: StatPoint[]` where `StatPoint = {time: string, value: number, type: string}`. `RefreshResponse` adds an `alerts` summary (`{rules, triggered, notified, resolved}`) from the post-collection evaluation; `ReportAlertEvent` carries each marker's time/state/reading.

#### `users.ts`

Full CRUD: `getUsers()`, `createUser(payload)`, `updateUser(id, payload)`, `deleteUser(id)`. `User` and `UserPayload` interfaces defined here.

#### `alerts.ts` / `channels.ts`

CRUD for the alerting admin pages. `alerts.ts` exposes `getAlerts/createAlert/updateAlert/deleteAlert/toggleAlert/testAlert`, plus `rearmAlert(id)` and `getAlertEvents(limit?)` for the activity log (`Alert` carries `channelIds[]`/`channelNames[]` and `lastState`; `AlertEvent` carries `state`, the readings, and `deliveries[]`). `channels.ts` exposes `getChannels/createChannel/updateChannel/deleteChannel` (`Channel` with `type`, `target`, optional `secret`). Backing the `AlertsPage` (multi-channel `CheckboxGroup`, inline enabled `Switch`) and `ChannelsPage`.

---

### 3.3 State Management

No Redux, no Zustand — the app is small enough that React's built-in primitives suffice.

#### `AuthContext` (`contexts/AuthContext.tsx`)

Provided at the root by `AuthProvider`. Exposes via `useAuth()`:

| Value | Type | Description |
|---|---|---|
| `token` | `string \| null` | Raw JWT from localStorage |
| `user` | `{username, roles[]} \| null` | Parsed JWT payload |
| `isAdmin` | `boolean` | `roles.includes('ROLE_ADMIN')` |
| `login(token)` | function | Persist token, decode payload |
| `logout()` | function | Clear token and user |

JWT payload decoding is done with `atob(token.split('.')[1])` — no library needed for reading the claims.

#### `useDevices` (`hooks/useDevices.ts`)

Fetches the device list on mount and re-fetches every 30 seconds (configurable). Returns `{devices, loading, error, refresh}`. The polling keeps the dashboard live without requiring WebSockets.

#### `useStats` (`hooks/useStats.ts`)

Fetches stats whenever `ain`, `type`, `from`, or `to` change (via `useEffect` dependency array). Skips the fetch if any parameter is empty, which prevents spurious requests during initial render.

---

### 3.4 Layout & Navigation

#### `AppLayout` (`components/layout/AppLayout.tsx`)

Outer shell for all authenticated pages. Renders `<TopBar />`, a `<StaleDataBanner />`, then a horizontal flex container of `<Sidebar />` + `<main>` (React Router `<Outlet />`).

#### `StaleDataBanner` (`components/layout/StaleDataBanner.tsx`)

Polls `GET /api/health` and, when `ageMinutes` exceeds the expected collection interval, shows a dismissible "live data may be stale" warning with how long ago the last collection succeeded — surfacing the case where the host slept and a scheduled run was skipped.

#### `TopBar` (`components/layout/TopBar.tsx`)

Blue horizontal header bar (AVM-style). Shows the diamond logo mark, app title, username, and a Sign Out button. Username and logout are here (not in the sidebar) to keep the sidebar purely navigational.

#### `Sidebar` (`components/layout/Sidebar.tsx`)

White left sidebar (200 px wide). Contains `<NavLink>` items for Dashboard and Reports; an admin-only group for Alerts, Channels, and Users; and a bottom group for Help. Active item is highlighted with a blue left border and light blue background, matching AVM's Fritz!Box UI style. No state of its own — purely presentational.

#### `PageHeader` (`components/layout/PageHeader.tsx`)

Reusable title block: `title`, optional `subtitle`, optional `actions` slot (React node rendered top-right). Used at the top of every page content area.

---

### 3.5 Pages

#### `LoginPage`

Renders a centred card with a blue banner header (AVM-inspired). Calls `loginRequest`, stores the token via `login()`, then navigates to `/dashboard`. Displays inline error on failure.

#### `DashboardPage`

Reads devices from `DeviceContext`. Refreshes on every visit (mount) and then polls every 30 s. Renders a `PageHeader` and a `DeviceTable`. Shows a loading state until the first fetch completes.

#### `DeviceDetailPage`

Reads `:ain` from URL params. Fetches the single device and 7-day history for all four metric types (temperature, power, energy, voltage) in parallel. Renders:

- Feature cards (Switch, Thermostat, PowerMeter, Temperature) conditionally based on the device's feature flags
- A chart for each metric type where data exists
- A back button to return to the dashboard

#### `ReportsPage`

Historical data explorer with a **compact toolbar**: a device selector, a "compare with" selector that
**overlays a second device** as a second series on the same chart, a metric selector, and a single
**date-range control** — a field-styled `Popover` holding the quick presets (Today / Yesterday / Last 7 /
Last 30 days) plus a custom from/to range. Below it, always-visible `ToggleChip`s control display options:
each rolling-average period, "Fit to data", and "Show alert events" — the last overlays **markers where
alert rules fired**, fetched from `GET /api/stats/alert-events`. A "Pull latest data" action (in the
`PageHeader`) collects fresh readings and re-evaluates alerts. The full filter is **persisted to
`localStorage`** (`phritzbox_reports_filter`) and restored — and auto-run — on return; if a date *preset*
was active it is re-resolved relative to today (so "Yesterday" stays current), otherwise the explicit
range is restored.

#### `UsersPage`

Admin-only. Lists users in a table. "New User" button opens a modal form (create). Each row has Edit and Delete actions. All mutations call the `users.ts` API functions and refresh the list on success.

#### `AlertsPage`

Admin-only. Lists alert rules with a compact condition column (e.g. `outdoor > indoor + 2 [°C]`), a current-**State** column (OK / Triggered with a **Re-arm** action on latched rules), an inline enabled `Switch`, and a per-row **Test** action. The modal form switches fields by rule mode (threshold vs comparison) and selects one or more channels via a `CheckboxGroup`. Below the rules, a **Recent activity** `DataTable` (from `getAlertEvents`) shows each firing/resolution/re-arm with the readings and per-channel delivery status.

#### `ChannelsPage`

Admin-only. CRUD for notification channels. The form adapts its `target`/`secret` fields to the chosen type (e.g. "Chat ID" + "Bot token" for Telegram). Deleting a channel still referenced by a rule surfaces the API's 409 message.

#### `HelpPage`

In-app user guide. A sectioned walkthrough (Dashboard, Controlling devices, Reports, Alerts, Channels, Users, Data collection & freshness) plus useful links and the app version. The three admin sections are gated behind `useAuth().isAdmin` so the guide stays relevant to the signed-in role. All copy is translated (en/de).

---

### 3.6 Device Components

#### `DeviceTable` (`components/device/DeviceTable.tsx`)

Renders a `DataTable` with columns: Name/AIN, Status (present badge), Switch state badge, Temperature (sensor °C or thermostat setpoint), Power (W), and Actions. The Actions column contains an `OutletToggle` and a Details link.

#### `OutletToggle` (`components/device/OutletToggle.tsx`)

Props: `ain`, `currentState ("on"|"off")`, `onToggled`. Shows "Turn On" (green) or "Turn Off" (red) depending on current state. Disables and shows "…" during the async API call. Calls `onToggled()` on success so the parent can refresh.

#### `SetpointControl` (`components/device/SetpointControl.tsx`)

Props: `ain`, `currentSetpoint`. Renders a range slider (8–28 °C) plus a text display. Debounces the `setSetpoint` API call so dragging the slider doesn't flood the Fritz!Box.

---

### 3.7 Chart Components

All charts are thin wrappers over `echarts-for-react`.

#### `TimeSeriesChart` (`components/charts/TimeSeriesChart.tsx`)

Generic base component. Props: `data: StatPoint[]`, `title`, `unit`, `color`. Builds an ECharts option object with a time-axis X axis, value Y axis, and a line series. Handles empty data gracefully. On the Reports page it also renders an **optional second series** (the compared device) and **alert-event markers** overlaid on the timeline.

#### Typed variants

Each wraps `TimeSeriesChart` with pre-configured `unit` and `color`:

| Component | Unit | Color |
|---|---|---|
| `TemperatureChart` | °C | Warm red |
| `PowerChart` | W | Orange |
| `EnergyChart` | Wh | Blue |
| `VoltageChart` | V | Purple |

These variants exist purely for semantic clarity at the call site — no logic differs.

---

### 3.8 UI Primitives

Located in `components/ui/`. These are lightweight CSS-class-based components, not a third-party component library.

| Component | Props | Description |
|---|---|---|
| `Button` | `variant`, `size`, `disabled`, `onClick` | Maps to `.btn--primary`, `.btn--secondary`, etc. |
| `Card` | `header`, `children` | `.card` wrapper with optional `.card-header` |
| `Badge` | `variant` | `.badge--success`, `--danger`, `--warning`, `--neutral` |
| `StatusDot` | `active: boolean` | Small coloured circle for online/offline |
| `DataTable` | `columns[]`, `rows[]`, `keyFn`, `emptyMessage` | Generic table renderer with zebra striping and a shaded header. A column with a `sortValue` accessor becomes click-to-sort (asc → desc → off) with a chevron indicator |
| `NotificationHost` | — | Top-centre toast stack fed by the `notifications` bus; renders global error messages (see §3.2) |
| `Modal` | `title`, `onClose`, `children` | Centred dialog used by the create/edit forms |
| `ConfirmDialog` + `useConfirm` | — | Styled confirmation dialog (replaces the browser's native `confirm()`); `useConfirm()` returns an async `confirm(opts)` that resolves to a boolean, used before destructive or device-control actions (delete, switch on/off) |
| `Popover` | `label`, `align`, `triggerClassName`, `children` | Click-toggled panel that closes on outside-click/Escape; backs the Reports date-range control |
| `ToggleChip` | `active`, `color?`, `onClick` | Pill toggle (filled when active, optional colour dot); the Reports display toggles |
| `SelectField` / `DateField` / `TextInput` | label + value/onChange | Labelled form-field wrappers used across the forms and the Reports toolbar |
| `CheckboxGroup` | `options[]`, `selected[]`, `onChange` | Multi-select used to attach channels to an alert rule |
| `Switch` | `checked`, `onChange` | Inline on/off toggle (e.g. a rule's enabled state) |

The decision to use plain CSS classes (tokens + global styles) rather than a library like shadcn/ui, MUI, or Tailwind was made to keep the bundle small and allow the AVM Fritz!Box visual style to be applied directly without fighting component defaults.

---

### 3.9 Frontend Dependencies

#### Production

| Package | Version | Why this, not an alternative |
|---|---|---|
| `react` + `react-dom` | ^19.2 | Declarative UI with hooks; industry standard for SPAs. Alternative: Vue — React chosen for broader ecosystem and TypeScript integration. |
| `react-router-dom` | ^7.18 | Client-side routing with nested routes and data loaders. Alternative: TanStack Router — React Router is the most familiar and has first-class Vite support. |
| `echarts` + `echarts-for-react` | ^6.1 / ^3.0 | Feature-rich charting with time-axis support, zoom, and tooltips. Alternative: Recharts — ECharts handles large time-series datasets more efficiently and provides built-in zoom/pan without extra plugins. |
| `i18next` + `react-i18next` (+ browser language detector) | ^26 / ^17 | English/German UI translations with a typed key catalogue (`src/i18n/resources.d.ts`); `npm run i18n:check` enforces en/de key parity. |

#### Development

| Package | Why |
|---|---|
| `vite` ^8 | Extremely fast HMR and ESM-native dev server. Alternative: webpack/CRA — Vite is significantly faster for development iteration and produces smaller bundles. |
| `@vitejs/plugin-react` | React Fast Refresh in the Vite dev server (no full page reload on component edits). |
| `typescript` ^6 | Static typing catches API shape mismatches at compile time, especially useful for the Device/Feature interfaces. |
| `vitest` ^4 + `@testing-library/react` + `jsdom` | Component/unit tests in a jsdom environment (see §6); `npm test` runs the suite. |
| `eslint` ^10 + `typescript-eslint` + `eslint-plugin-react-hooks` | Flat-config linting (`npm run lint`); the rules-of-hooks + exhaustive-deps checks. |

---

## 4. Infrastructure & Deployment

### 4.1 Docker Setup

```
docker/
├── compose.yaml                # Development (volume mounts)
├── compose.prod.yaml           # Production (pre-built images from ghcr.io)
├── Dockerfile.dev              # FrankenPHP dev image
├── Dockerfile.prod             # Multi-stage: Node → Composer → FrankenPHP
├── Caddyfile                   # Caddy/FrankenPHP web server config
├── .env.dist                   # Environment template
└── php/
    ├── entrypoint.sh           # JWT keygen, migrations, cache warmup, exec frankenphp
    └── php-prod.ini            # OPcache + production PHP settings
```

**Production services** (via `compose.prod.yaml`):

| Service | Image | Responsibility |
|---|---|---|
| `app` | `ghcr.io/ogmueller/phritzbox:latest` | FrankenPHP — serves static assets, runs PHP, handles all HTTP |
| `cronado` | `ghcr.io/teqneers/cronado:latest` | Watches Docker socket and triggers `cron:smart:savestats` every 30 min via container labels |

**Development** uses `compose.yaml` with `Dockerfile.dev` — same FrankenPHP base image but mounts `app/`, `data/`, and `var/` as volumes for live editing.

**Caddyfile strategy:**

- `php_server` — serves static files directly and rewrites non-file requests to `index.php`
- `/frontend/assets/*` — served with `Cache-Control: max-age=31536000, immutable` (Vite outputs content-hashed filenames)
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy`, `Strict-Transport-Security` (HSTS; honoured only over HTTPS), and `Permissions-Policy` (locks down camera/microphone/geolocation, which the dashboard never uses)

**Why FrankenPHP instead of Nginx + PHP-FPM?**
FrankenPHP embeds both the web server (Caddy) and the PHP runtime in a single process, eliminating the need for a separate reverse proxy and FastCGI socket. This simplifies the Docker setup to one container, reduces operational complexity, and provides automatic HTTPS via Let's Encrypt when a domain name is configured.

### 4.2 Build Pipeline

**Backend:** No compilation step. Symfony uses autowiring and attribute-based route discovery. `composer install --no-dev --optimize-autoloader` in production.

**Frontend:**

```
npm run build
  └── tsc -b          ← TypeScript type-check (fails build on type errors)
  └── vite build      ← Bundles and outputs to app/public/frontend/
```

Output: `index.html` + `assets/index-[hash].js` + `assets/index-[hash].css`. The content hash in filenames enables indefinite browser caching.

**Why Vite over webpack?**
Vite uses native ES modules in development (no bundling), making HMR near-instant. The production build uses Rollup under the hood with tree-shaking. For a project this size the developer experience difference over webpack is significant.

### 4.3 CI/CD Pipelines

Three GitHub Actions workflows in `.github/workflows/`, plus Dependabot:

| Workflow | Trigger | Purpose |
|---|---|---|
| `ci.yml` | Push to `main`, PRs | Parallel jobs: **Tests** (PHPUnit + coverage → Codecov), **Code Style** (php-cs-fixer), **Lint & Static Analysis** (PHPStan, YAML lint, Doctrine validation, PHPMD), and **Frontend** (`npm ci`, ESLint, Vitest, build, `npm audit`) |
| `security.yml` | Push, PRs, weekly | `composer audit`, dependency review |
| `docker.yml` | Push to `main`, tag `v*` | Build and push Docker image to `ghcr.io/ogmueller/phritzbox` |

`.github/dependabot.yml` opens automated dependency-update PRs (Composer, npm, GitHub Actions).

**Docker image tagging:**

| Event | Tags produced |
|---|---|
| Push to `main` | `nightly` |
| Push tag `v1.2.3` | `1.2.3`, `1.2`, `latest` |

The Docker workflow uses `docker/metadata-action` for tag generation and `docker/build-push-action` with GitHub Actions cache for efficient builds.

### 4.4 Environment Variables

| Variable | Where used | Example |
|---|---|---|
| `APP_ENV` | Symfony | `prod` |
| `APP_SECRET` | Symfony CSRF/cookies | random 32-char string |
| `DATABASE_URL` | Doctrine | `sqlite:///%kernel.project_dir%/../data/database.sqlite` |
| `APP_API_DOMAIN` | `Helper` | `http://fritz.box` |
| `APP_API_URL_LOGIN` | `Helper` | `{APP_API_DOMAIN}/login_sid.lua` |
| `APP_API_URL_AHA` | `Helper` | `{APP_API_DOMAIN}/webservices/homeautoswitch.lua` |
| `APP_API_USERNAME` | `Helper` | Fritz!Box username |
| `APP_API_PASSWORD` | `Helper` | Fritz!Box password |
| `JWT_SECRET_KEY` | Lexik JWT | `%kernel.project_dir%/config/jwt/private.pem` |
| `JWT_PUBLIC_KEY` | Lexik JWT | `%kernel.project_dir%/config/jwt/public.pem` |
| `JWT_PASSPHRASE` | Lexik JWT | Key passphrase |
| `SERVER_NAME` | Caddy/FrankenPHP | `http://localhost` (scheme = plain HTTP; bare domain enables auto-HTTPS) |
| `CORS_ALLOW_ORIGIN` | Nelmio CORS | `^http://localhost:5173$` (dev only) |
| `MAILER_DSN` | Symfony Mailer (e-mail alerts) | `null://null` (default, discards) or `smtp://user:pass@host:587` |
| `APP_ALERT_FROM` | Alert e-mail sender | `alerts@phritzbox.local` |

Sensitive values (passwords, key passphrase) go in `.env.local` which is gitignored. For Docker production, these are set in `docker/.env` (copied from `docker/.env.dist`).

---

## 5. Data Flows

### 5.1 Device Control (e.g. turning an outlet on)

```
User clicks "Turn On" in OutletToggle
  → api.post('/api/devices/{ain}/on')           [client.ts adds JWT header]
  → FrankenPHP routes to Symfony
  → JWT firewall validates token
  → DeviceController::switchOn(ain)
  → AhaApi::setSwitchOn(ain)
  → Helper::requestUrl('/homeautoswitch.lua?switchcmd=setswitchon&ain=...')
  → Fritz!Box router changes physical outlet state
  → HTTP 200 OK
  → OutletToggle calls onToggled()
  → DashboardPage calls refresh()
  → useDevices re-fetches GET /api/devices
  → UI re-renders with updated state
```

### 5.2 Stats Collection (cron job)

```
Cron: php bin/console cron:smart:savestats
  → AhaApi::getDeviceListInfos()                [parses XML → Device[]]
  → for each Device with powerMeter or temperature:
      AhaApi::getBasicDeviceStats(ain)           [parses XML → nested array]
      determine shortest time interval
      for each (type, timestamp, value):
          if SmartDeviceDataRepository finds no row for (ain, type, time):
              persist new SmartDeviceData entity
  → EntityManager::flush()                       [batch INSERT to SQLite]
```

### 5.3 Stats Query (frontend chart)

```
DeviceDetailPage mounts, calls useStats(ain, 'temperature', sevenDaysAgo, now)
  → api.get('/api/stats/{ain}?type=temperature&from=...&to=...')
  → StatsController::stats(ain)
  → SmartDeviceDataRepository
      ->createQueryBuilder('d')
      ->where('d.sid = :ain AND d.type = :type AND d.time BETWEEN :from AND :to')
      ->getResult()
  → JSON: {ain, type, data: [{timestamp, value}, ...]}
  → useStats stores in local state
  → TemperatureChart renders ECharts line series
```

---

## 6. Testing

The backend test suite lives in `app/tests/` and runs with PHPUnit 13. The frontend has its own suite (see below).

### Structure

```
tests/
├── bootstrap.php                  # Load .env.test, boot kernel
├── Helper.php                     # Test utilities
├── Command/
│   ├── CommandTestCase.php        # Base: mocks AhaApi + EntityManager; ArrayAdapter cache
│   ├── SmartSwitch{On,Off,Toggle,Power,Energy,Name,Present,List}Test.php
│   ├── SmartDevice{List,Stats}Test.php
│   ├── SmartSrc{Setpoint,Comfort,Saving}Test.php
│   └── SmartTemperatureTest.php
├── Device/Feature/
│   ├── TemperatureTest.php        # XML parsing, unit conversion
│   ├── PowerMeterTest.php
│   └── OutletTest.php
└── Entity/
    └── SmartDeviceDataTest.php    # Entity field assignment
```

### Approach

**Command tests** use `CommandTestCase` which provides:
- A mock `AhaApi` (controllable return values, assertion of which methods were called)
- A mock `EntityManagerInterface`
- An `ArrayAdapter` cache (in-memory, no filesystem)
- A `runCommand(inputArray, options)` helper that wires everything into a `CommandTester`

**Feature tests** construct a minimal XML string, call `setXml()`, and assert the parsed property values — verifying the Fritz!Box unit conversions (÷10 for temperature, ÷1000 for power/voltage).

**`dama/doctrine-test-bundle`** wraps integration tests in a transaction that is rolled back after each test, avoiding table truncation and keeping tests fast.

### Frontend tests

Component tests run with **Vitest** in a **jsdom** environment (`@testing-library/react` + `@testing-library/user-event`). `src/test/setup.ts` wires up jsdom (incl. a `localStorage` polyfill) and `@testing-library/jest-dom` matchers. Tests live alongside the code they cover (e.g. `DataTable.test.tsx`, `NotificationHost.test.tsx`). Run with `npm test` (CI uses `vitest run`).

---

## 7. Key Architectural Decisions

### Why SQLite?

Fritz!Box devices report at most one reading per 5 minutes per metric. Even across 20 devices over 10 years that is under 50 million rows — well within SQLite's comfortable range. There is no need for a server process, no connection pooling, no backups beyond copying a single file. For a home automation app running on a NAS or Raspberry Pi, SQLite is the right default. The `DATABASE_URL` env var makes it easy to swap in PostgreSQL or MySQL if scale ever becomes a concern.

### Why JWT over sessions?

The API is consumed by a React SPA that may be served from a different origin during development. Sessions require cookie handling and CSRF protection. JWTs are stateless — the server validates the signature without a lookup, which matters for the CLI tools that also authenticate. The RS256 algorithm (asymmetric) means the public key can be distributed to verify tokens without exposing the signing key.

### Why `NativeHttpClient` instead of cURL?

Fritz!Box routers close TLS connections with an abrupt `close_notify` alert. PHP's cURL binding raises a `CURLE_SSL_CONNECT_ERROR` in this case. PHP's native stream wrapper (used by `NativeHttpClient`) silently accepts the incomplete shutdown. This is the specific reason `Helper` does not use the default `HttpClient::create()`.

### Why a hand-rolled API client (`client.ts`) instead of Axios or React Query?

The application makes a small number of endpoint types. Axios would add ~50 kB to the bundle for functionality that a 40-line `fetch` wrapper covers. React Query's caching and background refetch model is compelling but adds complexity and a learning curve — `useDevices` with `setInterval` does the same job for one data type. If the number of endpoints grows, migrating to TanStack Query would be a reasonable next step.

### Why ECharts over Recharts or Chart.js?

ECharts renders to Canvas (not SVG), which handles thousands of time-series points without DOM node bloat. It provides built-in data zoom and tooltip following out of the box, which are the two interactions most useful for reviewing power and temperature history. Recharts is declarative and React-idiomatic but struggles with dense datasets and requires extra plugins for zoom.

### Why Vite instead of Create React App / webpack?

Create React App is effectively unmaintained. Vite's native ESM dev server gives sub-100 ms HMR for this codebase size. The production build uses Rollup with tree-shaking and automatic code splitting. The `/api` dev proxy is configured in one line of `vite.config.ts`.

### Why plain CSS over Tailwind or a component library?

The goal is to match AVM's Fritz!Box visual design — a specific look with precise colours, border styles, and layout rules. Utility-first CSS (Tailwind) would require customising the entire design system config, and fighting component library defaults (MUI, Ant Design) to override their appearance is often more work than writing targeted CSS. The CSS token system in `tokens.css` + `global.css` gives full control with no runtime overhead.

### Why the Feature bitmask pattern?

Fritz!Box devices can combine multiple capabilities (e.g. a smart outlet that also has a power meter and temperature sensor). Modelling this as a bitmask avoids a fragile inheritance hierarchy or a wide nullable-column entity. Each capability is an isolated object that knows how to parse its XML fragment and serialise itself, making it straightforward to add a new feature type (e.g. a DECT repeater, alarm contact) without touching existing code.
