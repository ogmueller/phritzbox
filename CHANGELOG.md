# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Min/Max markers on aggregated charts now show the true extremes.** Previously a chart covering more than two days marked the highest and lowest *averaged* point; a brief spike inside an averaging window was invisible. The markers now read from the underlying bucket's real minimum and maximum, so the reported figures on long-range charts will be further apart than before — and correct. Short-range charts (up to two days) are unaffected, since they plot individual readings.

### Added
- **Pre-aggregated report tiers.** Readings are summarised into quarter-hourly and daily buckets (`cron:smart:rollup`), so long reports read a few thousand summary rows instead of scanning millions of raw ones. Build them once from existing history with `smart:rollup:backfill` — resumable, interruptible, and safe to run against live data. Report figures are unchanged: a bucket-served point is identical to the raw-served one it replaces.
- **CSV / JSON export** from Reports, covering exactly the window on screen (`GET /api/stats/{ain}/export`). Aggregated exports include per-bucket `min` and `max` columns; the CSV delimiter follows the interface language so German Excel opens it correctly.
- `GET /api/stats/{ain}` now reports which tier answered the query (`resolution`: `raw`, `quarter` or `day`), and aggregated points carry `min`/`max`.
- **Database backups** — `cron:data:backup` writes a verified, compressed snapshot using SQLite's `VACUUM INTO` (so the app keeps running), checks it with `PRAGMA quick_check`, and rotates older ones. `data:backup:list` and `data:backup:verify` inspect them; `data:restore` replaces the live database, taking a `pre-restore-*` copy first so the restore itself can be undone.
- **Battery and availability are recorded as metrics**, which makes "thermostat battery below 20%" and "device offline for an hour" ordinary alert rules.
- Thermostat details on the device page — setpoint, comfort and saving temperatures, battery, window-open, boost and error state (previously the API never sent them, so the card could not render).
- `GET /api/health` also reports `lastRollupAt` and `lastBackupAt`, so a scheduled job that was never configured is visible rather than silent.
- **Energy costs.** Enter your electricity price and monthly standing charge on the new **Settings** page (admin only) and the recorded energy readings turn into money: four headline tiles on the Dashboard (live draw, today, month-to-date cost, standby), and a per-device cost readout under the Reports chart whenever the energy metric is selected. Until a tariff is set, every cost reads `—` rather than `0.00`, because "no price configured" and "costs nothing" are different statements. The standing charge is prorated by calendar month and appears only in household totals — it is billed per meter connection, so apportioning it across devices would make per-device costs non-additive. Costs use your *current* price for any range, including multi-year ones, so the readout says "at the current tariff" rather than claiming to reproduce a historical bill.
- **Consumption coverage is stated, not assumed.** The box reports energy once per day and missed days are never backfilled, so a range total is a lower bound. The readout always says how many of the range's days actually carry data, and warns only when days are genuinely *missing* — a device installed halfway through the range has fewer days but no gap, so it stays quiet instead of crying wolf on every new device.
- **Standby detection** — each device's idle floor, taken as the 5th percentile of the quarter-hourly minimum over the last 7 days, with what a year at that floor would cost. Shown on the device's power-meter card and summed into a Dashboard tile (`GET /api/energy/standby/{ain}`). Read from the summary tiers rather than raw readings, so it keeps working once retention has pruned old raw data. A device with too little history shows nothing at all rather than `0 W` — "we don't know" and "it draws nothing" must not look alike. Because that floor spans the whole week, on or off, a device you switch off reads `0 W` — right for what it costs, silent about the appliance — so the payload also carries **how much of the window the device was on** and **what it drew while on** (`dutyCyclePercent`, `idleWatts`): a printer that runs 3% of the week shows `0 W` standby and `14 W` while on. The idle figure is not annualised, because it applies only while the device is on and a week's usage pattern is not a yearly forecast. The two figures pick their tier independently: an appliance run in short blocks leaves too few whole-on quarter-hour buckets for a percentile, so the idle draw falls back to raw readings (reported as `idleSource`) while the floor still comes from the summary tier.
- **Compare a period with itself.** Reports' "Compare with" select gained **Previous period**: it overlays the same metric from the immediately preceding window of equal length, shifted forward so the two lines sit on top of each other. Tooltips show each series' own original timestamp, so the comparison stays readable.
- `GET /api/energy/cost` (per-device and household cost plus coverage for a window) and `GET /api/energy/summary` (today, month-to-date, top consumer, standby — one request for the whole dashboard). Both return plain numbers and an ISO 4217 code; formatting is the browser's job, so a German and an English reader each see their own conventions.
- **Settings page** (admin) with `GET`/`PUT /api/settings/tariff`. Reading the tariff needs `ROLE_USER` — everyone needs the price to see costs — while changing it needs `ROLE_ADMIN`. Stored in a new typed `tariff` table rather than a key/value row, so a future "price valid from" column turns it into tariff history without a redesign. Amounts are accepted in either decimal convention — `0,35` and `0.35` are the same price — because the German UI asks for a comma and a refused price is indistinguishable from a tariff that was never set. No new environment variable: the tariff is configured in the UI, not in the compose file.

### Fixed
- **A device's lifetime energy counter was reported 1000× too small.** The Fritz!Box sends `<voltage>` in millivolts and `<power>` in milliwatts, but `<energy>` already in watt-hours; all three were being divided by 1000, so an outlet with 8 Wh of lifetime energy displayed as `0.008 Wh`. The figure shown on the device detail page and by `smart:device:list` is now correct — and 1000× larger than before. The stored `energy` metric used by charts and reports is a separate per-day series and was never affected.
- **Charts covering more than 30 days showed a duplicated point for the current day.** The daily summary tier was split against the quarter-hour watermark, which is a 15-minute boundary and therefore mid-day, so the whole-day summary and that day's later readings were both emitted. Retention now also removes only whole days, for the same reason.
- Non-admin users could not change their own password: `/api/users/me/password` was covered by the admin-only rule for `/api/users`.
- Notification channel secrets were returned in plaintext by the API. They are now write-only; the edit form leaves the field blank and keeps the stored token unless a new one is entered.
- A fresh database could not be migrated from scratch — no migration ever created `smart_device_data`.
- `smart:template:list` fetched the template list and printed nothing.
- Rule-based alerting system. Define rules in the web UI (admin) that fire when a device metric crosses a threshold, stays past it for a sustained period, or relates to another device's metric (e.g. tempA > tempB + 2). Evaluated shortly after each data collection via the new `cron:smart:alerts` command, with a per-rule cooldown and a "send test" button.
- Reusable notification channels, managed in their own admin module (Channels); each alert rule can notify one or more of them. Built-in channel types: e-mail, generic webhook, Pushover, Telegram, ntfy, Discord, Gotify, and Slack-compatible (Slack/Mattermost/Rocket.Chat).
- Alert activity log — a "Recent activity" section on the Alerts page (and `GET /api/alerts/events`) records every firing, resolution, and manual re-arm with the readings and **per-channel delivery status** (sent / failed + error message), so a silently failing notification is now visible. Stored in the new `alert_event` table.
- Alert rule state + manual re-arm — each rule row shows its current state (OK / Triggered); a **Re-arm** action (`POST /api/alerts/{id}/rearm`) resets a latched rule so it can fire again on the next evaluation.
- "Pull latest data" in Reports now also evaluates alert rules immediately against the freshly collected readings (previously only the scheduled cron evaluated them).
- Reports can overlay a **second device** on the same chart for side-by-side comparison, and mark **alert events** on the timeline where rules fired (`GET /api/stats/alert-events`), via a redesigned compact toolbar (time-range quick ranges/custom popover plus always-visible display toggles).
- Data-staleness detection — `GET /api/health` reports how long ago collection last succeeded (tracked in a new `app_state` table), and the UI shows a "live data may be stale" banner when the host has been asleep or the scheduler stalled.
- Styled, in-app confirmation dialogs replace the browser's native `confirm()` for destructive and device-control actions (delete, switch on/off).
- Sortable, zebra-striped tables with a shaded header across the app (Dashboard, Alerts, Users, Channels, and the activity log); click a column header to sort, click again to reverse.
- Global "system message" toasts for serious HTTP errors (5xx responses or an unreachable server), so background data loads no longer fail silently.
- Reports remembers your last selection (device, compared device, metric, time range, averages, "Fit to data") and auto-runs it on return; a saved quick range such as "Last 24 hours" re-resolves against the current moment.
- Dashboard fetches fresh data on every visit, in addition to the 30-second auto-refresh.
- In-app Help page rewritten as a sectioned, role-aware user guide (admin sections shown only to admins), in English and German.
- Continuous integration now also runs **PHPStan** (level 6) and a dedicated **frontend job** (ESLint, Vitest, build, `npm audit`); **Dependabot** opens dependency-update PRs.

### Changed
- Reports quick ranges are now **rolling windows that always end at "now"** instead of calendar spans: "Today" and "Yesterday" are replaced by **Last 24 hours** and **Last 48 hours** (alongside Last 7/30 days). The old labels were misleading — "Yesterday" actually queried *yesterday 00:00 until the end of today*, roughly a 48-hour window, which the German label already admitted with "Seit gestern". Saved filters holding the old preset keys migrate automatically. The field is now labelled **Time range** and its heading **Quick ranges**.
- `GET /api/stats/{ain}` and `GET /api/stats/alert-events` accept `from`/`to` as an offset-bearing ISO 8601 instant in addition to a bare `Y-m-d` (which still covers the whole day). This fixes a timezone bug: the UI sent a browser-local date that the server, running in UTC, read as UTC midnight — so every range was shifted by the viewer's UTC offset. A malformed bound now returns `400` instead of `500`.
- Alerts notify only when a condition becomes true (the triggering edge). When a condition clears again, the resolution is recorded in the activity log but **no "resolved" message is sent**.
- Upgraded core dependencies: Symfony 8.1, PHPUnit 13, React 19, echarts 6, Vite 8, TypeScript 6.

### Security
- JWT access tokens are short-lived (`token_ttl` 3600 s) and silently renewed via a rotating refresh token, limiting the window a leaked token is useful.
- Added `Strict-Transport-Security` (HSTS) and `Permissions-Policy` response headers.

### Fixed
- Report chart tooltips no longer show duplicated values on short date ranges. The root cause — duplicate `(sid, type, time)` rows created by overlapping collection runs (a manual pull racing the cron) — was removed, and prevented going forward with a UNIQUE index plus idempotent (`INSERT OR IGNORE`) data collection.
- Production container no longer crash-loops when its persisted volume held cache files from an older image version: prod now persists only `var/log` (not the whole `var/`), keeping the Symfony cache ephemeral, and the startup cache wipe is best-effort so a stray permission error can't abort boot.

## [1.1.0] - 2026-06-22

### Added
- On-demand data refresh in the Reports section — a "Pull latest data" button triggers immediate collection from the Fritz!Box (`POST /api/stats/refresh`), in addition to the 30-minute automatic collection
- Quick date-range presets in Reports (Today, Yesterday, Last 7 days, Last 30 days)

### Performance
- **Reports are dramatically faster.** Added the missing `(sid, type, time)` database index, so historical queries use an index seek instead of scanning the entire table. On a ~52M-row database this took report queries from ~1.4 s to a few milliseconds — hundreds of times faster — across all date ranges.
- Data collection fetches each device's stats concurrently (bounded) instead of one blocking request after another, cutting import time by roughly 30%.
- Data collection's "last seen" lookup now uses per-device index seeks instead of a full-table `GROUP BY`, cutting that step from ~4.4 s to ~5 ms per run.
- Chart rolling-average computation reduced from O(n²) to O(n), and dense series are now downsampled (LTTB) before rendering.

### Fixed
- Startup now rebuilds the Symfony cache, so an upgraded image never runs against a stale compiled container left on a persisted `var/` volume (previously caused 500 errors after image updates)
- Caddy storage directories are writable by the non-root user, removing permission warnings on startup
- Healthcheck `start_period` increased so a slow first-boot migration (e.g. building an index over millions of rows) is not flagged unhealthy

## [1.0.0] - 2026-04-20

### Added
- React web dashboard with live device status, interactive charts, and user management
- REST API for devices, statistics, and user administration
- JWT-based authentication with login rate limiting
- Password change endpoint (`PUT /api/users/me/password`)
- Docker Compose setup for production deployment with pre-built images from ghcr.io
- GitHub Actions workflow for building and pushing Docker images on tags and main branch
- FrankenPHP as application server (replaces Nginx + PHP-FPM)
- Cronado for scheduled data collection (replaces shell-loop cron)
- German and English translations
- Automated data collection via `cron:smart:savestats` command
- Security headers in Caddyfile configuration
- React error boundary for graceful error handling
- Lazy-loaded routes for smaller initial bundle size
- SECURITY.md for vulnerability reporting
- ARCHITECTURE.md for technical documentation
- Web UI screenshots in README

### Changed
- License clarified as MIT (matching LICENSE file)
- README restructured: marketing and screenshots first, Docker quick start, then developer docs
- Docker setup simplified to single FrankenPHP container (was Nginx + PHP-FPM)

### Security
- Removed debug `dump()` calls from API client
- Added role validation to prevent privilege escalation
- Added backend protection against self-deletion of user accounts
- Added login throttling (5 attempts per minute)

[Unreleased]: https://github.com/ogmueller/phritzbox/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ogmueller/phritzbox/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ogmueller/phritzbox/releases/tag/v1.0.0
