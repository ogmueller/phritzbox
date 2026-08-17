# Phritzbox — Docker Installation

A self-hosted dashboard for AVM FRITZ!Box smart home devices — live monitoring, control,
historical charts, and rule-based alerting. For the full feature list and screenshots see the
[project README](https://github.com/ogmueller/phritzbox#features).

## Requirements

- [Docker](https://docs.docker.com/get-docker/) with Compose

## Setup

1. Copy `.env.dist` to `.env` and edit it with your Fritz!Box credentials:

   ```bash
   cp .env.dist .env
   ```

2. Start the application:

   ```bash
   docker compose up -d
   ```

3. Visit `http://localhost` and log in with `admin` / `admin`.

> **Important:** Change the default password immediately after your first login.

## Configuration

All settings are configured via environment variables in `.env`:

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_API_USERNAME` | Yes | — | Fritz!Box login username |
| `APP_API_PASSWORD` | Yes | — | Fritz!Box login password |
| `APP_API_DOMAIN` | No | `http://fritz.box` | Fritz!Box address (or MyFRITZ! URL) |
| `APP_SECRET` | No | `change-me...` | Symfony application secret |
| `JWT_PASSPHRASE` | No | `change-me` | Passphrase for JWT key encryption |
| `SERVER_NAME` | No | `http://localhost` | Server hostname (use bare domain for auto-HTTPS) |
| `PHRITZBOX_PORT` | No | `80` | Port to expose the web UI |
| `MAILER_DSN` | No | `null://null` | SMTP DSN for e-mail alerts (e.g. `smtp://user:pass@host:587`); default discards mail. Webhook/push alert channels need no mail server. |
| `APP_ALERT_FROM` | No | `alerts@phritzbox.local` | Sender address for alert e-mails |

## CLI Commands

Use the included `console` script to run commands inside the container:

```bash
./console smart:device:list
./console smart:switch:toggle 12345678901
./console                          # shows all available commands
```

## Data Collection

Readings are collected from the FRITZ!Box every 30 minutes by the bundled `cronado` scheduler.
It does **not** catch up on missed runs, so keep the host always on — if the machine sleeps,
collection (and alert evaluation) pauses until it wakes. When data goes stale, the web UI shows a
"live data may be stale" banner with how long ago the last collection succeeded.

## Updating

Pull the latest image and restart:

```bash
docker compose pull
docker compose up -d
```

**Also refresh `compose.yaml` when a release adds a scheduled job.** The cron schedules live in
the `labels:` of *your* `compose.yaml`, not inside the image — so pulling a new image alone never
adds them. Alert evaluation (`cronado.alerts.*`) was added this way, and nightly backups
(`cronado.backup.*`) after it: an installation set up before each shipped keeps collecting
readings but silently never runs the new job.

```bash
# compare your file against the current one
curl -L https://raw.githubusercontent.com/ogmueller/phritzbox/main/docker/compose.prod.yaml \
  | diff - compose.yaml

# after updating the file: recreate the app container so the new labels apply,
# and restart the scheduler so it re-reads them
docker compose up -d
docker compose restart cronado

# verify — every label set must be present
docker inspect --format '{{json .Config.Labels}}' "$(docker compose ps -q app)" | tr ',' '\n' | grep cronado
```

You should see `cronado.savestats.*`, `cronado.alerts.*`, `cronado.rollup.*`,
`cronado.prune.*` and `cronado.backup.*`. `GET /api/health` also reports `lastRollupAt` and
`lastBackupAt`; either staying `null` means that job has never run.

## Rollups

Readings arrive about every 24 seconds per outlet, which makes long reports
expensive and the database large. `cron:smart:rollup` keeps two pre-aggregated
tiers — quarter-hourly and daily — so a year-long chart reads a few thousand
summary rows instead of scanning millions of raw ones.

After upgrading to a release that adds rollups, build them once from your
existing history:

```bash
docker compose exec app php bin/console smart:rollup:backfill
```

It is safe to run against live data (it only writes to the rollup table),
resumable, and interruptible — `--max-seconds=60` processes a slice at a time
and saves progress. From then on the hourly cron job keeps the tiers current.

## Retention (optional)

Once the rollups exist, the original per-reading rows for old periods are
redundant — the summaries cover them. `cron:data:prune` can remove them.

> [!IMPORTANT]
> **Deleting is opt-in and irreversible.** Every retention window defaults to
> `0`, meaning keep forever, so the scheduled job reports and removes nothing
> until you set one. Take a backup and verify it first.

```bash
# always start here — reports what each setting would remove, changes nothing
docker compose exec app php bin/console cron:data:prune --dry-run
```

### The three tiers

The same readings are stored at three resolutions, and **each tier is the
fallback for the one above it**. That is what decides what a retention window
actually costs you:

| Variable | Holds | Rows¹ | If you prune it |
|---|---|---:|---|
| `APP_RETENTION_RAW_DAYS` | Every individual reading (~24 s apart for power and voltage, 15 min for temperature) | ~52,000,000 | Charts of that period drop to 15-minute detail |
| `APP_RETENTION_QUARTER_DAYS` | One summary per 15 minutes — average, minimum, maximum | ~1,060,000 | Charts of that period drop to daily detail |
| `APP_RETENTION_DAILY_DAYS` | One summary per day — average, minimum, maximum | ~22,000 | **That period disappears from charts entirely** — nothing coarser exists behind it |

¹ Indicative, from a seven-year install with ten devices.

So prune from the top down. In practice:

- **`APP_RETENTION_RAW_DAYS` is the only one worth setting.** It is ~98% of the
  database, and dropping it still leaves you 15-minute resolution.
- **`APP_RETENTION_QUARTER_DAYS`** is worth setting only if the quarter-hour tier
  itself grows inconvenient — at ~1 M rows for seven years, that is unlikely.
- **Leave `APP_RETENTION_DAILY_DAYS` at `0`.** The daily tier is a rounding error
  in size and is your permanent history; deleting it is the one setting here that
  loses information outright rather than reducing its resolution.

| Other windows | Default | Meaning |
|---|---|---|
| `APP_RETENTION_RAW_OVERRIDES` | – | Per-metric override of the raw window (see below) |
| `APP_RETENTION_ALERT_EVENT_DAYS` | `0` | Keep the alert activity log for N days |
| `APP_RETENTION_LOG_DAYS` | `14` | Sweep rotated log files after N days |

### Per-metric raw windows

`APP_RETENTION_RAW_OVERRIDES` takes comma-separated `metric=days` pairs and
overrides `APP_RETENTION_RAW_DAYS` for just those metrics:

```
APP_RETENTION_RAW_OVERRIDES=voltage=14,power=90
```

All six metric names, and whether an override is worth setting:

| Metric | Sampled | Share of the database | Worth overriding? |
|---|---|---|---|
| `power` | ~24 s | ~50% | **Yes** — one of the only two that matter |
| `voltage` | ~24 s | ~50% | **Yes** — same volume as power, and mains voltage barely varies, so it tolerates the shortest window |
| `temperature` | 15 min | ~0.8% | Rarely — it is small, and the 15-minute tier already stores it losslessly |
| `energy` | once a day | negligible | No |
| `battery` | once per collection | negligible | No |
| `presence` | once per collection | negligible | No |

Anything other than these six names is ignored, so a typo silently has no
effect — `cron:data:prune` warns when it sees one. A value of `0` or less is
also ignored, so a mistake cannot be read as "delete everything".

> In practice, on a database dominated by old data, a single
> `APP_RETENTION_RAW_DAYS` does nearly all the work and per-metric tuning is a
> rounding error. Run `--dry-run` with and without an override and compare the
> numbers before adding complexity.

Raw readings are only ever deleted where a rollup bucket already covers them:
the cutoff is the *earlier* of your retention window and how far the rollup has
progressed. If the rollup job has never run, nothing is pruned no matter what
these are set to.

Recommended first run, once you have a verified backup:

```bash
docker compose exec app php bin/console cron:data:backup
docker compose exec app php bin/console data:backup:verify
# set APP_RETENTION_RAW_DAYS in compose.yaml, then:
docker compose up -d
docker compose exec app php bin/console cron:data:prune --dry-run
docker compose exec app php bin/console cron:data:prune
```

### Why the file does not shrink

Deleting rows does **not** reduce the size on disk. SQLite keeps the freed pages
on an internal free list and reuses them for future writes, so the file stays at
its high-water mark. The command reports the free-page total so you can see the
space was released internally.

To actually give the space back to the filesystem, rebuild the file:

```bash
docker compose exec app php bin/console cron:data:prune --vacuum
```

This needs roughly as much free disk as the database itself and holds an
exclusive lock for minutes on a multi-gigabyte file — do it once, in a
maintenance window, not from cron.

## Backups

A verified snapshot of the database is written nightly at 04:20 by
`cron:data:backup`. It uses SQLite's `VACUUM INTO`, so the app keeps running
while it works, and each snapshot is checked with `PRAGMA quick_check` before
older ones are rotated away — a snapshot that fails verification is discarded and
your existing backups are left alone.

```bash
# run one now
docker compose exec app php /application/app/bin/console cron:data:backup

# see what it would do, without writing
docker compose exec app php /application/app/bin/console cron:data:backup --dry-run
```

| Variable | Default | Meaning |
|---|---|---|
| `APP_BACKUP_DIR` | `/application/data/backups` | Where snapshots are written |
| `APP_BACKUP_KEEP` | `3` | How many to retain |

> [!IMPORTANT]
> The default target is **inside the `phritzbox-data` volume**. That protects you
> against corruption, a bad migration or an accidental deletion — but *not*
> against losing the volume or the host, because the backups die with it. For
> copies that survive, bind-mount a directory into the `app` service and point
> `APP_BACKUP_DIR` at it:
>
> ```yaml
> services:
>     app:
>         volumes:
>             - /mnt/nas/phritzbox-backups:/backups
>         environment:
>             APP_BACKUP_DIR: /backups
> ```

Each snapshot is a gzipped copy of the **whole** database, so budget disk
accordingly before raising `APP_BACKUP_KEEP`.

### Checking your backups

An untested backup is a hypothesis. `data:backup:verify` decompresses a snapshot,
runs SQLite's integrity check, confirms it really is a Phritzbox database, and
reports what it holds:

```bash
docker compose exec app php bin/console data:backup:list
docker compose exec app php bin/console data:backup:verify           # newest
docker compose exec app php bin/console data:backup:verify --all     # every one
```

It exits non-zero if any snapshot fails, so it can be wired into monitoring.

### Restoring

```bash
docker compose exec app php bin/console data:restore --dry-run   # verify only

docker compose stop app
docker compose run --rm app php bin/console data:restore
docker compose up -d
```

The command verifies the snapshot, copies the database it is about to overwrite
to `pre-restore-<timestamp>.sqlite` beside it, and only then swaps it in — so a
restore made in a hurry can itself be undone, and a snapshot that fails
verification never gets near your live data.

> [!IMPORTANT]
> **Stop the app first.** Replacing the database file underneath a running
> process leaves that process holding the old file open, so it carries on
> serving pre-restore data until it restarts — a restore that appears to have
> done nothing. The command cannot detect this for you: SQLite only takes locks
> during transactions, so an idle connection would not trip a lock check.

Your readings, users, and alert rules live in named volumes, so recreating the container leaves
them untouched.

## More Information

- Project: https://github.com/ogmueller/phritzbox
- Issues: https://github.com/ogmueller/phritzbox/issues
