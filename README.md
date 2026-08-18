Phritzbox
=========

[![CI](https://github.com/ogmueller/phritzbox/actions/workflows/ci.yml/badge.svg)](https://github.com/ogmueller/phritzbox/actions/workflows/ci.yml)
[![Docker](https://github.com/ogmueller/phritzbox/actions/workflows/docker.yml/badge.svg)](https://github.com/ogmueller/phritzbox/actions/workflows/docker.yml)
[![Security](https://github.com/ogmueller/phritzbox/actions/workflows/security.yml/badge.svg)](https://github.com/ogmueller/phritzbox/actions/workflows/security.yml)
[![codecov](https://codecov.io/gh/ogmueller/phritzbox/branch/main/graph/badge.svg)](https://codecov.io/gh/ogmueller/phritzbox)
[![ghcr.io](https://img.shields.io/badge/ghcr.io-phritzbox-blue?logo=docker)](https://ghcr.io/ogmueller/phritzbox)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A self-hosted smart home dashboard for smart devices connected to AVM Fritz!Box. Monitor temperatures, power consumption, and energy usage with interactive charts. Control smart outlets and radiator thermostats from your browser or the command line.

Built with Symfony 8, React 19, and [FrankenPHP](https://frankenphp.dev). Ships as a single Docker image.


Screenshots
-----------

**Dashboard** — live overview of all devices with status, temperature, power consumption, and toggle switches:

![Dashboard](app/files/screenshots/web-dashboard.png)

**Device detail** — product image, firmware info, and feature cards for switch state, power meter, and temperature sensor, followed by interactive 7-day charts (temperature, power, energy in kWh, and voltage) with rolling averages and a raw-XML view for diagnostics:

![Device detail](app/files/screenshots/web-device-detail.png)

**Reports** — query historical data for any device and time range, with selectable metric, configurable rolling averages, an optional second device *or the previous period* overlaid for comparison, a cost readout when the energy metric is selected, CSV/JSON export, and markers where alert rules fired (hover a marker to see the rule and reading):

![Reports — two devices compared, with alert event markers](app/files/screenshots/web-reports-temp-alerts.png)
![Reports — 30 days of energy with the cost readout](app/files/screenshots/web-reports-energy-cost.png)

**Alerts** _(admin)_ — threshold, sustained, and device-to-device comparison rules with per-channel delivery, a manual re-arm, and an activity log of every firing and resolution:

![Alerts](app/files/screenshots/web-alerts.png)

**Channels** _(admin)_ — reusable notification destinations (e-mail, webhook, Pushover, Telegram, ntfy, Discord, Gotify, Slack/Mattermost) shared across alert rules:

![Channels](app/files/screenshots/web-channels.png)

**Users** _(admin)_ — user management with role-based access: administrators configure everything, while regular users monitor and control devices and view reports:

![Users](app/files/screenshots/web-users.png)

**Settings** _(admin)_ — the electricity tariff used to turn recorded energy into costs: price per kWh, monthly standing charge, and currency. See [Energy costs](#energy-costs):

![Settings](app/files/screenshots/web-settings.png)


Features
--------

- Live device status with 30-second auto-refresh (and a fresh pull on every visit)
- Interactive charts for temperature, power, energy, and voltage
- Time-range reports with rolling quick ranges (last 24/48 hours, last 7/30 days — always ending *now*), rolling averages, a second device **or the previous period** overlaid on the same chart for comparison, and alert events marked where rules fired — plus CSV/JSON export and on-demand data refresh; your last selection is remembered and re-run when you come back
- Energy costs — enter your electricity price once and see what today, this month, and each individual device actually cost, plus your household's standby draw and what a year of it would come to
- Rule-based alerting (threshold, sustained, or device-to-device comparison) via e-mail, webhook, Pushover, Telegram, ntfy, Discord, Gotify, or Slack/Mattermost — with an activity log that shows per-channel delivery status, current-rule state, and a manual re-arm
- Sortable tables and global error notifications throughout the UI
- 27 CLI commands for device control, monitoring, and data maintenance (backup, restore, rollup, retention)
- User management with role-based access (admin only)
- German and English interface
- Automated data collection every 30 minutes (via [cronado](https://github.com/teqneers/cronado))
- Single Docker image — no PHP, Node.js, or Composer required


Quick Start
-----------

Requires [Docker](https://docs.docker.com/get-docker/) with Compose.

**Option A — Download the release zip** (recommended):

Download the latest `phritzbox-*.zip` from [Releases](https://github.com/ogmueller/phritzbox/releases), extract it, and copy `.env.dist` to `.env`:

```bash
unzip phritzbox-*.zip && cd phritzbox
cp .env.dist .env
```

**Option B — Fetch files manually:**

```bash
mkdir phritzbox && cd phritzbox
curl -L https://raw.githubusercontent.com/ogmueller/phritzbox/main/docker/compose.prod.yaml -o compose.yaml
curl -L https://raw.githubusercontent.com/ogmueller/phritzbox/main/docker/.env.dist -o .env
```

Edit `.env` with your Fritz!Box credentials, then start the application:

```bash
docker compose up -d
```

Visit `http://localhost` and log in with `admin` / `admin`.

> **Important:** Change the default password immediately after your first login.

To use a specific version instead of `latest`, edit the image tag in `compose.yaml`:

```
image: ghcr.io/ogmueller/phritzbox:1.1.0   # tagged release
image: ghcr.io/ogmueller/phritzbox:nightly  # latest from main branch
```


Configuration
-------------

All settings are configured via environment variables in `.env`:

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `APP_API_USERNAME` | Yes | — | Fritz!Box login username |
| `APP_API_PASSWORD` | Yes | — | Fritz!Box login password |
| `APP_API_DOMAIN` | No | `http://fritz.box` | Fritz!Box address (or MyFRITZ! URL) |
| `APP_SECRET` | No | `change-me...` | Symfony application secret |
| `JWT_PASSPHRASE` | No | `change-me` | Passphrase for JWT key encryption |
| `SERVER_NAME` | No | `http://localhost` | Server hostname for Caddy (use a bare domain, e.g. `phritzbox.example.com`, to get automatic HTTPS) |
| `PHRITZBOX_PORT` | No | `80` | Port to expose the web UI |
| `MAILER_DSN` | No | `null://null` | Mail transport for e-mail alerts (e.g. `smtp://user:pass@host:587`); default discards mail |
| `APP_ALERT_FROM` | No | `alerts@phritzbox.local` | Sender address for alert e-mails |


CLI Commands
------------

Using the release zip, run commands with the included `console` script:

```bash
./console COMMAND
```

Or via `docker compose exec` directly:

```bash
docker compose exec app php /application/app/bin/console COMMAND
```

Without Docker:

```bash
php app/bin/console COMMAND
```

| Command | Description |
|---------|-------------|
| `smart:device:list` | List all available SmartHome devices |
| `smart:device:stats` | Show statistics of a SmartHome device |
| `smart:device:xml <ain>` | Dump raw AHA XML for a device (useful for bug reports) |
| `smart:switch:list` | List all known SmartHome outlets |
| `smart:switch:on <ain>` | Turn on a SmartHome outlet |
| `smart:switch:off <ain>` | Turn off a SmartHome outlet |
| `smart:switch:toggle <ain>` | Toggle power state of a SmartHome outlet |
| `smart:switch:power <ain>` | Read current power consumption [mW] |
| `smart:switch:energy <ain>` | Read energy delivered over outlet [Wh] |
| `smart:switch:present <ain>` | Check availability of a SmartHome outlet |
| `smart:switch:name <ain>` | Get name of a SmartHome outlet |
| `smart:temperature <ain>` | Read temperature of a SmartHome device [°C] |
| `smart:src:on <ain>` | Turn on a smart radiator control |
| `smart:src:off <ain>` | Turn off a smart radiator control |
| `smart:src:setpoint <ain>` | Read or set target temperature [°C] |
| `smart:src:comfort <ain>` | Read comfort temperature setpoint [°C] |
| `smart:src:saving <ain>` | Read saving temperature setpoint [°C] |
| `smart:template:list` | List all available SmartHome templates |
| `cron:smart:savestats` | Collect and persist all device data |
| `cron:smart:alerts` | Evaluate alert rules and send notifications |
| `cron:smart:rollup` | Aggregate new readings into the rollup tiers |
| `smart:rollup:backfill` | Build the rollup tiers from existing history |
| `cron:data:prune` | Apply retention windows (deletes data — opt-in) |
| `cron:data:backup` | Write a verified, compressed snapshot of the database |
| `data:backup:list` | List available database snapshots |
| `data:backup:verify` | Check that a snapshot is intact and restorable |
| `data:restore` | Replace the live database with a verified snapshot |


Data Collection
---------------

In the Docker setup, data collection runs automatically every 30 minutes via [cronado](https://github.com/teqneers/cronado).

For a bare-metal installation, set up a cron job:

```cron
*/30 * * * *   /path-to-phritzbox/app/bin/console cron:smart:savestats
```

The Fritz!Box caches device data. Temperature readings are available for up to 24 hours — if not fetched in time they are lost. Running every 30 minutes is recommended.

**Keep the host awake.** The scheduler ([cronado](https://github.com/teqneers/cronado)) does not catch up missed runs, so if the machine sleeps (e.g. a laptop closing its lid), collection and alert evaluation simply pause until it wakes. Run Phritzbox on always-on hardware, or disable system sleep on the host. If data does go stale, the web UI shows a "live data may be stale" banner with how long ago the last collection succeeded.


Energy costs
------------

Phritzbox already records how much energy each outlet delivers. Enter your electricity price and it
turns that into money.

**Setup** — as an administrator, open **Settings** in the sidebar and fill in:

| Field | Meaning |
|---|---|
| Price per kWh | What you pay per kilowatt-hour, in your currency (e.g. `0.35`) |
| Standing charge per month | The fixed monthly fee on your bill (*Grundpreis*) — leave empty if you have none |
| Currency | Used to format every amount shown in the UI |

No environment variable, no restart — the tariff lives in the database and takes effect immediately.
Leave the price empty and Phritzbox shows consumption without ever guessing at a cost.

**Where costs appear:**

- **Dashboard** — four tiles above the device table: what the household is drawing right now, energy
  used today, month-to-date cost, and standby draw with what a year at that floor would cost.
- **Reports** — pick the *energy* metric and a cost readout appears under the chart for exactly the
  window on screen: that device's energy and cost, its share of the household, and the household total.
- **Device detail** — the device's energy chart with figures in kWh, plus its own standby draw and
  annual standby cost on the power-meter card.

**Standby** is each device's idle floor: the 5th percentile of its quarter-hourly minimum draw over the
last week. It answers "what does this appliance cost me while I'm not using it?" — a figure that is
usually invisible and often surprising. Devices that are genuinely switched off most of the week
correctly report `0 W`, while a device with less than a week of history shows no figure at all rather
than a misleading zero.

### What the figures do and don't claim

These caveats are shown in the UI too, but they are worth understanding once:

- **A range total is a lower bound.** The Fritz!Box reports energy once per day and Phritzbox never
  backfills a day it missed, so if collection was down the total is short by those days. The readout
  always says how many of the range's days actually carry data, and warns when days are missing. A
  device you installed halfway through the range has fewer days but hasn't lost anything, so it stays
  quiet.
- **Costs use your current price for any range**, including multi-year ones — the readout says "at the
  current tariff" rather than pretending to reproduce an old bill. Tariff history is a planned follow-up.
- **The standing charge appears only in household totals**, never per device. It's billed per meter
  connection, so splitting it across devices would make a device that consumed nothing appear to cost
  money. It's prorated by calendar month, so a whole month comes out at exactly the figure on your bill.
- **Day boundaries are UTC**, matching the container's timezone.
- **Readings from before 16 July 2026** are attributed one day later than they are today, because the
  collector's day labelling changed then. Period totals are unaffected; only per-day attribution across
  that date shifts. Ranges that straddle it carry a footnote.


Alerts
------

Define rules that notify you when something noteworthy happens — turning Phritzbox into a simple smart-temperature manager for your home.

**Example — when to open or close the windows in summer:** put one temperature sensor outdoors and one indoors, then create a *comparison* rule like **“outdoor < indoor − 2 °C”**. When the outside air drops below your indoor temperature you get a notification telling you it's time to open up and let the cool air in; the inverse rule (**“outdoor > indoor”**) tells you when to close the windows and keep the heat out.

**Rule types:**

- **Threshold** — a metric crosses a value, e.g. *temperature above 25 °C* or *power below 5 W* (an appliance finished).
- **Sustained** — a threshold holds for the last *N* minutes, e.g. *power above 2000 W for 5 minutes*.
- **Comparison** — one device's metric relative to another's, ± an offset, e.g. *outdoor temperature < indoor temperature − 2 °C*.

Rules are **edge-triggered**: you're alerted once when the condition becomes true, and again only after it clears and re-occurs. Set an optional *reminder interval* if you'd rather be re-notified while it stays true. A message is sent **only on the triggering edge** — when the condition clears again the resolution is logged but not notified.

Each rule shows its **current state** (OK / Triggered) on the Alerts page. A rule with "alert once" stays *triggered* until the condition clears; if you want it to fire again sooner, use the **Re-arm** action to reset it to OK. The **Recent activity** log below the rules records every firing, resolution, and re-arm together with the readings and the **per-channel delivery result** (sent ✓ / failed ✗ with the error), so a misconfigured channel is easy to spot.

**Channels** are the delivery destinations, managed separately and reusable across rules. A rule can notify **one or more** channels. Supported types:

| Channel | You provide |
|---------|-------------|
| E-mail | recipient address (set `MAILER_DSN` to send) |
| Webhook | a URL (receives a JSON payload) |
| Pushover | user/group key + application token |
| Telegram | chat ID + bot token |
| ntfy | topic URL (+ optional auth token) |
| Discord | channel webhook URL |
| Gotify | server URL + app token |
| Slack / Mattermost / Rocket.Chat | incoming-webhook URL |

Set up channels under **Channels**, then reference them from **Alerts** (both are admin-only). Use a channel's **Test** button to verify delivery.

Alert rules are evaluated shortly after each data collection (the `cron:smart:alerts` command, scheduled automatically in the Docker setup). Because evaluation runs on collected data, alerts can lag real-time by up to the collection interval (~30 minutes). To check immediately, the Reports **"Pull latest data"** button collects fresh readings *and* evaluates the rules right away.

**No alerts arriving?** Work through this in order — the command prints exactly what it did:

```bash
docker compose exec app php /application/app/bin/console cron:smart:alerts
# → Evaluated 3 rule(s): 3 triggered, 0 notified, 0 resolved
```

- **`Evaluated 0 rule(s)`** — no *enabled* rules exist in that installation's database.
- **`0 triggered`** — the conditions genuinely aren't met. A *sustained* rule additionally needs **every** sample in its window to satisfy the condition, and is skipped entirely if the window contains no readings.
- **triggered but `0 notified`** — the rules are already in the *Triggered* state. With "alert once" they stay silent until the condition clears, so a rule whose condition is permanently true never alerts again. Use **Re-arm** on the Alerts page, or set a *reminder interval* to be re-notified while it stays true.
- **notified but nothing received** — check the per-channel delivery result in **Recent activity**, and use the channel's **Test** button.
- **the command works by hand but nothing happens on its own** — the scheduler isn't running it. The cron jobs come from **labels in your `compose.yaml`**, not from the image, so an installation created before alerting shipped keeps collecting data without ever evaluating rules. Verify with:

  ```bash
  docker inspect --format '{{json .Config.Labels}}' "$(docker compose ps -q app)" | tr ',' '\n' | grep cronado
  ```

  You should see a `cronado.savestats.*`, `cronado.alerts.*`, `cronado.rollup.*`, `cronado.prune.*` and `cronado.backup.*` set. If any are missing, refresh your `compose.yaml` (see [Updating](docker/INSTALL.md#updating)). `GET /api/health` also reports `lastRollupAt` and `lastBackupAt`, which stay `null` while a job has never run.


Development
-----------

### Requirements

* PHP 8.5+ with `pdo_sqlite` and `simplexml`
* Node.js 22+ and npm
* Composer 2
* A Fritz!Box with smart home devices

### Manual Installation

```bash
git clone https://github.com/ogmueller/phritzbox.git
cd phritzbox/app && composer install && cd ..
cp app/.env app/.env.local
```

Edit `app/.env.local` and set your Fritz!Box credentials:

```dotenv
APP_API_USERNAME=your-username
APP_API_PASSWORD=your-password
APP_API_DOMAIN=http://fritz.box
DATABASE_URL="sqlite:///%kernel.project_dir%/../data/database.sqlite"
```

Run the initial database migration:

```bash
php app/bin/console doctrine:migrations:migrate
```

### Docker (Development)

```bash
cd docker && docker compose up
```

The container mounts `app/`, `data/`, and `var/` as volumes for live code editing.

> **Note:** The Docker dev setup only runs the PHP backend. To work on the frontend, you need to start the Vite dev server separately (see below) or uncomment the `vite` service in `docker/compose.yaml`.

> **Port:** the Vite dev server proxies `/api` to `http://localhost:38080`, so publish the dev
> container on that port — put `PHRITZBOX_PORT=38080` in `docker/.env` (the production default of
> `80` would leave the frontend dev server without a backend).

### Frontend

```bash
cd app/frontend && npm install
npm run dev     # dev server on :5173 (proxies /api to the app container on :38080)
npm run build   # production build → app/public/frontend/
```

### Tests

```bash
php app/vendor/bin/phpunit --configuration app/phpunit.xml.dist   # backend (PHPUnit)
cd app/frontend && npm test                                       # frontend (Vitest)
```

### Code Style

```bash
(cd app && vendor/bin/php-cs-fixer fix --diff --dry-run -v --config .php-cs-fixer.dist.php)   # check
(cd app && vendor/bin/php-cs-fixer fix --config .php-cs-fixer.dist.php)                       # fix
```

### Lint & Validate

```bash
cd app && composer phpstan                          # static analysis (PHPStan, level 8)
php app/bin/console lint:yaml app/config --parse-tags
php app/bin/console doctrine:schema:validate --skip-sync
cd app && composer audit                            # backend dependency audit
cd app/frontend && npm run lint                     # ESLint
cd app/frontend && npm run i18n:check               # en/de translation parity
```

These same checks run in CI on every push and pull request (PHPUnit + coverage, php-cs-fixer,
PHPStan, PHPMD, YAML/Doctrine validation, and a frontend job: ESLint, Vitest, build, `npm audit`).
Dependency updates are proposed automatically by Dependabot.

### Building Docker Images Locally

```bash
docker build -f docker/Dockerfile.prod -t phritzbox .
```


<details>
<summary>CLI Screenshots</summary>

**Device listing** (`smart:device:list`):

![Device listing](app/files/screenshots/device-listing.png)

**Statistics** (`smart:device:stats`):

![Temperature graph](app/files/screenshots/temperature-graph-cli.png)
![Voltage graph](app/files/screenshots/voltage-graph-cli.png)
![Power graph](app/files/screenshots/power-graph-cli.png)
![Energy per month over 1 year](app/files/screenshots/energy-1yr-graph-cli.png)
![Energy per day over 1 month](app/files/screenshots/energy-1mo-graph-cli.png)

</details>
