# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Rule-based alerting system. Define rules in the web UI (admin) that fire when a device metric crosses a threshold, stays past it for a sustained period, or relates to another device's metric (e.g. tempA > tempB + 2). Evaluated shortly after each data collection via the new `cron:smart:alerts` command, with a per-rule cooldown and a "send test" button.
- Reusable notification channels, managed in their own admin module (Channels); each alert rule can notify one or more of them. Built-in channel types: e-mail, generic webhook, Pushover, Telegram, ntfy, Discord, Gotify, and Slack-compatible (Slack/Mattermost/Rocket.Chat).
- Alert activity log — a "Recent activity" section on the Alerts page (and `GET /api/alerts/events`) records every firing, resolution, and manual re-arm with the readings and **per-channel delivery status** (sent / failed + error message), so a silently failing notification is now visible. Stored in the new `alert_event` table.
- Alert rule state + manual re-arm — each rule row shows its current state (OK / Triggered); a **Re-arm** action (`POST /api/alerts/{id}/rearm`) resets a latched rule so it can fire again on the next evaluation.
- "Pull latest data" in Reports now also evaluates alert rules immediately against the freshly collected readings (previously only the scheduled cron evaluated them).
- Sortable, zebra-striped tables with a shaded header across the app (Dashboard, Alerts, Users, Channels, and the activity log); click a column header to sort, click again to reverse.
- Global "system message" toasts for serious HTTP errors (5xx responses or an unreachable server), so background data loads no longer fail silently.
- Reports remembers your last selection (device, metric, date preset/range, averages, "Fit to data") and auto-runs it on return; a saved preset such as "Yesterday" re-resolves relative to the current day.
- Dashboard fetches fresh data on every visit, in addition to the 30-second auto-refresh.

### Changed
- Alerts notify only when a condition becomes true (the triggering edge). When a condition clears again, the resolution is recorded in the activity log but **no "resolved" message is sent**.

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
