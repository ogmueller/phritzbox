# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- On-demand data refresh in the Reports section — a "Pull latest data" button triggers immediate collection from the Fritz!Box (`POST /api/stats/refresh`), in addition to the 30-minute automatic collection
- Quick date-range presets in Reports (Today, Yesterday, Last 7 days, Last 30 days)
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

### Performance
- **Reports are dramatically faster.** Added the missing `(sid, type, time)` database index, so historical queries use an index seek instead of scanning the entire table. On a ~52M-row database this took report queries from ~1.4 s to a few milliseconds — hundreds of times faster — across all date ranges.
- Data collection's "last seen" lookup now uses per-device index seeks instead of a full-table `GROUP BY`, cutting that step from ~4.4 s to ~5 ms per run.
- Chart rolling-average computation reduced from O(n²) to O(n), and dense series are now downsampled (LTTB) before rendering.

### Changed
- License clarified as MIT (matching LICENSE file)
- README restructured: marketing and screenshots first, Docker quick start, then developer docs
- Docker setup simplified to single FrankenPHP container (was Nginx + PHP-FPM)

### Fixed
- Startup now rebuilds the Symfony cache, so an upgraded image never runs against a stale compiled container left on a persisted `var/` volume (previously caused 500 errors after image updates)
- Caddy storage directories are writable by the non-root user, removing permission warnings on startup
- Healthcheck `start_period` increased so a slow first-boot migration (e.g. building an index over millions of rows) is not flagged unhealthy

### Security
- Removed debug `dump()` calls from API client
- Added role validation to prevent privilege escalation
- Added backend protection against self-deletion of user accounts
- Added login throttling (5 attempts per minute)
