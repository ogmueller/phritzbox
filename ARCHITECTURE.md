# Phritzbox — Architecture Documentation

## Overview

Phritzbox is a self-hosted smart home dashboard for smart devices connected to AVM Fritz!Box. It provides a React web UI and a Symfony REST API that bridge the Fritz!Box AHA (AVM Home Automation) HTTP API, storing time-series telemetry and exposing device control to authenticated users.

**Stack summary:** PHP 8.5 / Symfony 8 · SQLite / Doctrine ORM · JWT authentication · React 18 / Vite · ECharts · Docker (FrankenPHP)

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

#### Repositories

Both extend `ServiceEntityRepository` and rely on Doctrine's auto-wired query builder. `SmartDeviceDataRepository` is used by `StatsController` to filter by AIN, type, and time range. `UserRepository` is used by the security provider and `UserController`.

#### Migration

`Version20260411024338` creates the `user` table, adds unique indexes on `username` and `email`, and seeds a default `admin` / `admin` account (bcrypt cost 12). **The default password must be changed after first deployment.**

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
| GET | `/api/stats/{ain}` | ROLE_USER | Query time-series data; query params: `type`, `from` (default: `-24 hours`), `to` (default: `now`) |
| GET | `/api/stats/types/{ain}` | ROLE_USER | List available metric types for a device |

#### `UserController` — `/api/users`

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/users` | ROLE_ADMIN | List all users |
| POST | `/api/users` | ROLE_ADMIN | Create user (JSON: `username`, `email`, `password`, `roles`) |
| PUT | `/api/users/{id}` | ROLE_ADMIN | Update user (partial) |
| DELETE | `/api/users/{id}` | ROLE_ADMIN | Delete user (self-deletion blocked with 403) |
| PUT | `/api/users/me/password` | ROLE_USER | Change own password (JSON: `currentPassword`, `newPassword`) |

Role validation restricts assignable roles to `ROLE_USER` and `ROLE_ADMIN` — unknown roles return 400. Password hashing uses Symfony's `UserPasswordHasherInterface` (bcrypt by default).

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
| `cron:smart:savestats` | `CronSmartSaveStats` | **Primary data collection entry point.** Fetches stats for all devices, finds the shortest interval (highest resolution), stores new datapoints in `smart_device_data`, skipping rows that already exist for a given timestamp+type. Designed to run every 5–30 minutes via cron. |
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
3. Token is returned in the response body
4. All subsequent API calls include `Authorization: Bearer <token>`
5. The `api` firewall validates the signature against the public key on every request (stateless — no session)

Access control matrix:

| Path | Required role |
|---|---|
| `/api/auth/login` | PUBLIC_ACCESS |
| `/api/users/**` | ROLE_ADMIN |
| `/api/**` | ROLE_USER |
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
| `ext-simplexml` | Fritz!Box XML API responses are parsed with SimpleXML. Alternative: DOMDocument — SimpleXML is lighter for read-only XML traversal. |
| `noximo/php-colored-ascii-linechart` | ASCII charts for `smart:device:stats` CLI output. No comparable Composer alternative for terminal line charts. |

#### Development

| Package | Purpose |
|---|---|
| `phpunit/phpunit` ^11 | Unit and integration testing |
| `symfony/phpunit-bridge` | Symfony-aware test utilities (deprecation handler, etc.) |
| `dama/doctrine-test-bundle` | Wraps each test in a transaction and rolls back, giving fast isolated DB tests without truncating tables |
| `friendsofphp/php-cs-fixer` | Enforces PSR-12 + custom rules; configured in `.php-cs-fixer.dist.php` |
| `phpmd/phpmd` | Static analysis for code smells |
| `roave/security-advisories` | Blocks installation of packages with known CVEs |

---

## 3. Frontend

### 3.1 Entry Point & Routing

**`main.tsx`** — mounts `<App />` into `#root`.

**`App.tsx`** — sets up the full application tree. All page components are lazy-loaded via `React.lazy()` and wrapped in `<Suspense>` for code splitting (ECharts ~800 kB is only loaded when chart pages are visited). An `ErrorBoundary` wraps the entire app to catch rendering errors and display a user-friendly reload prompt instead of a white screen.

```
ErrorBoundary
└── AuthProvider
    └── BrowserRouter
        └── Suspense
            ├── /login              → LoginPage         (lazy, public)
            └── RequireAuth
                └── AppLayout
                    ├── /dashboard          → DashboardPage    (lazy)
                    ├── /devices/:ain       → DeviceDetailPage (lazy)
                    ├── /reports            → ReportsPage      (lazy)
                    └── RequireAdmin
                        └── /users          → UsersPage        (lazy)
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
4. On **401** — clears the token and redirects to `/login`
5. On non-2xx — throws an `Error` with the status code
6. On **204** — returns `undefined` (no body)

This keeps all auth-token logic in one place so individual API modules stay clean.

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

`getStats(ain, type?, from?, to?)` and `getStatTypes(ain)`. The `StatsResponse` interface contains `ain`, `type`, and `data: StatPoint[]` where `StatPoint = {timestamp: string, value: number}`.

#### `users.ts`

Full CRUD: `getUsers()`, `createUser(payload)`, `updateUser(id, payload)`, `deleteUser(id)`. `User` and `UserPayload` interfaces defined here.

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

Outer shell for all authenticated pages. Renders `<TopBar />`, then a horizontal flex container of `<Sidebar />` + `<main>` (React Router `<Outlet />`).

#### `TopBar` (`components/layout/TopBar.tsx`)

Blue horizontal header bar (AVM-style). Shows the diamond logo mark, app title, username, and a Sign Out button. Username and logout are here (not in the sidebar) to keep the sidebar purely navigational.

#### `Sidebar` (`components/layout/Sidebar.tsx`)

White left sidebar (200 px wide). Contains `<NavLink>` items for Dashboard, Reports, and (if admin) Users. Active item is highlighted with a blue left border and light blue background, matching AVM's Fritz!Box UI style. No state of its own — purely presentational.

#### `PageHeader` (`components/layout/PageHeader.tsx`)

Reusable title block: `title`, optional `subtitle`, optional `actions` slot (React node rendered top-right). Used at the top of every page content area.

---

### 3.5 Pages

#### `LoginPage`

Renders a centred card with a blue banner header (AVM-inspired). Calls `loginRequest`, stores the token via `login()`, then navigates to `/dashboard`. Displays inline error on failure.

#### `DashboardPage`

Fetches all devices via `useDevices` (30 s poll). Renders a `PageHeader` and a `DeviceTable`. Shows a loading state until the first fetch completes.

#### `DeviceDetailPage`

Reads `:ain` from URL params. Fetches the single device and 7-day history for all four metric types (temperature, power, energy, voltage) in parallel. Renders:

- Feature cards (Switch, Thermostat, PowerMeter, Temperature) conditionally based on the device's feature flags
- A chart for each metric type where data exists
- A back button to return to the dashboard

#### `ReportsPage`

Date-range filter (from/to date inputs + metric type selector) and a `useStats` hook. Renders the relevant `TimeSeriesChart` variant for the selected device + metric.

#### `UsersPage`

Admin-only. Lists users in a table. "New User" button opens a modal form (create). Each row has Edit and Delete actions. All mutations call the `users.ts` API functions and refresh the list on success.

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

Generic base component. Props: `data: StatPoint[]`, `title`, `unit`, `color`. Builds an ECharts option object with a time-axis X axis, value Y axis, and a line series. Handles empty data gracefully.

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
| `DataTable` | `columns[]`, `rows[]`, `emptyMessage` | Generic table renderer; columns define header + cell render function |

The decision to use plain CSS classes (tokens + global styles) rather than a library like shadcn/ui, MUI, or Tailwind was made to keep the bundle small and allow the AVM Fritz!Box visual style to be applied directly without fighting component defaults.

---

### 3.9 Frontend Dependencies

#### Production

| Package | Version | Why this, not an alternative |
|---|---|---|
| `react` + `react-dom` | ^18.3 | Declarative UI with hooks; industry standard for SPAs. Alternative: Vue — React chosen for broader ecosystem and TypeScript integration. |
| `react-router-dom` | ^6.28 | Client-side routing with nested routes and data loaders. Alternative: TanStack Router — React Router v6 is the most familiar and has first-class Vite support. |
| `echarts` + `echarts-for-react` | ^5.6 / ^3.0 | Feature-rich charting with time-axis support, zoom, and tooltips. Alternative: Recharts — ECharts handles large time-series datasets more efficiently and provides built-in zoom/pan without extra plugins. |

#### Development

| Package | Why |
|---|---|
| `vite` ^5 | Extremely fast HMR and ESM-native dev server. Alternative: webpack/CRA — Vite is significantly faster for development iteration and produces smaller bundles. |
| `@vitejs/plugin-react` | React Fast Refresh in the Vite dev server (no full page reload on component edits). |
| `typescript` ^5.6 | Static typing catches API shape mismatches at compile time, especially useful for the Device/Feature interfaces. |

---

## 4. Infrastructure & Deployment

### 4.1 Docker Setup

```
docker/
├── docker-compose.yml          # Development (volume mounts)
├── docker-compose.prod.yml     # Production (pre-built images from ghcr.io)
├── Dockerfile.dev              # FrankenPHP dev image
├── Dockerfile.prod             # Multi-stage: Node → Composer → FrankenPHP
├── Caddyfile                   # Caddy/FrankenPHP web server config
├── .env.dist                   # Environment template
└── php/
    ├── entrypoint.sh           # JWT keygen, migrations, cache warmup, exec frankenphp
    └── php-prod.ini            # OPcache + production PHP settings
```

**Production services** (via `docker-compose.prod.yml`):

| Service | Image | Responsibility |
|---|---|---|
| `app` | `ghcr.io/ogmueller/phritzbox:latest` | FrankenPHP — serves static assets, runs PHP, handles all HTTP |
| `cronado` | `ghcr.io/teqneers/cronado:latest` | Watches Docker socket and triggers `cron:smart:savestats` every 30 min via container labels |

**Development** uses `docker-compose.yml` with `Dockerfile.dev` — same FrankenPHP base image but mounts `app/`, `data/`, and `var/` as volumes for live editing.

**Caddyfile strategy:**

- `php_server` — serves static files directly and rewrites non-file requests to `index.php`
- `/frontend/assets/*` — served with `Cache-Control: max-age=31536000, immutable` (Vite outputs content-hashed filenames)
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy`

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

Three GitHub Actions workflows in `.github/workflows/`:

| Workflow | Trigger | Purpose |
|---|---|---|
| `ci.yml` | Push to `main`, PRs | PHPUnit tests with coverage, php-cs-fixer, YAML lint, Doctrine validation, PHPMD |
| `security.yml` | Push, PRs, weekly | `composer audit`, dependency review |
| `docker.yml` | Push to `main`, tag `v*` | Build and push Docker image to `ghcr.io/ogmueller/phritzbox` |

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
| `SERVER_NAME` | Caddy/FrankenPHP | `localhost` (or domain for auto-HTTPS) |
| `CORS_ALLOW_ORIGIN` | Nelmio CORS | `^http://localhost:5173$` (dev only) |

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

Test suite lives in `app/tests/` and runs with PHPUnit 11.

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
