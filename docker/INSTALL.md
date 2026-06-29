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

## More Information

- Project: https://github.com/ogmueller/phritzbox
- Issues: https://github.com/ogmueller/phritzbox/issues
