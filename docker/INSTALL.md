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

# verify — all three label sets must be present
docker inspect --format '{{json .Config.Labels}}' "$(docker compose ps -q app)" | tr ',' '\n' | grep cronado
```

You should see `cronado.savestats.*`, `cronado.alerts.*` and `cronado.backup.*`.

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
accordingly before raising `APP_BACKUP_KEEP`. Restoring is just gunzip and
replace:

```bash
docker compose down
gunzip -c phritzbox-YYYYmmdd-HHMMSS.sqlite.gz > database.sqlite   # into the data volume
docker compose up -d
```

Your readings, users, and alert rules live in named volumes, so recreating the container leaves
them untouched.

## More Information

- Project: https://github.com/ogmueller/phritzbox
- Issues: https://github.com/ogmueller/phritzbox/issues
