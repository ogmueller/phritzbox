# Phritzbox — Docker Installation

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

## CLI Commands

Use the included `console` script to run commands inside the container:

```bash
./console smart:device:list
./console smart:switch:toggle 12345678901
./console                          # shows all available commands
```

## Updating

Pull the latest image and restart:

```bash
docker compose pull
docker compose up -d
```

## More Information

- Project: https://github.com/ogmueller/phritzbox
- Issues: https://github.com/ogmueller/phritzbox/issues
