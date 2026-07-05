# Pennyhunt — Production Deployment

> Last updated: 2026-07-05
> Status: **LIVE** at https://pennyhunt.wecode.dev (Laravel Forge, Linode `139.162.134.88`)

## Overview

Production runs on a single Laravel Forge–provisioned Ubuntu 24.04 server
(host `PennyHunt`, 8 GB RAM, 157 GB disk). SSH access: `forge@139.162.134.88`.
The site is a Forge zero-downtime deployment at
`/home/forge/pennyhunt.wecode.dev` (`current` → `releases/NNNNNN`, shared
`.env` and `storage/` symlinked into each release). Repo:
`github.com/ossedk/pennyhunt`, branch `main`.

**Constraint that shaped the setup:** the `forge` user has only limited
passwordless sudo (php-fpm reload, nginx service, supervisorctl). No root
shell, no `apt`. Forge provisioned the box with MySQL, but the app requires
PostgreSQL 16 — so Postgres runs **in user space** from portable binaries.

## Components

| Component | How it runs | Where |
|---|---|---|
| PostgreSQL 16.14 | portable binaries ([theseus-rs/postgresql-binaries](https://github.com/theseus-rs/postgresql-binaries)), systemd **user** unit `pennyhunt-postgres` | `/home/forge/pgsql16` (binaries + `data/`), listens `127.0.0.1:5432` |
| Horizon (queues) | systemd user unit `pennyhunt-horizon` | Redis-backed, prefix `pennyhunt_horizon:` |
| Reverb (websockets) | systemd user unit `pennyhunt-reverb` | `127.0.0.1:8080` |
| Scheduler | forge crontab, `schedule:run` every minute | `crontab -l` as forge |
| LLM backfill | persistent systemd user unit `pennyhunt-classify-backfill` | `~/run-classify-backfill.sh` (loops until candidate set exhausted; deploy-safe — re-enters `current` each iteration, retries failed dry-runs instead of treating them as done) |
| ML training venv | `uv`-managed Python 3.12 venv (scikit-learn, pandas, numpy) | `/home/forge/venvs/pennyhunt` — `PENNYHUNT_ML_PYTHON` points here |
| Web | nginx (Forge-managed, TLS on 443) → php8.5-fpm | root `current/public` |

User lingering is enabled (`loginctl enable-linger forge`), so the systemd
user units start at boot and survive SSH logout.

### Database

- DB `pennyhunt`, user `forge` (password in server `.env`), local-only.
- Seeded 2026-07-04 from a full local dump (`pg_dump -Fc`, 275 MB → 1.9 GB
  restored: 746k raw_posts, 10.4k tickers, all backtest runs and models).
- The local Mac database remains as a dev copy; **production is now the
  source of truth** — do not dump local over prod again without checking
  what prod has ingested since.

Connect on the server:

```bash
PGPASSWORD='<see .env>' /home/forge/pgsql16/bin/psql -h 127.0.0.1 -U forge -d pennyhunt
```

### Service management (as forge)

```bash
systemctl --user status pennyhunt-postgres pennyhunt-horizon pennyhunt-reverb
systemctl --user restart pennyhunt-horizon        # after each deploy
journalctl --user -u pennyhunt-classify-backfill -f  # watch LLM backfill
```

Unit files live in `~/.config/systemd/user/`.

### Deploying new code

Forge "Deploy" works (repo is connected), or manually:

```bash
cd /home/forge/pennyhunt.wecode.dev/current
git pull origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
systemctl --user restart pennyhunt-horizon   # pick up new job code
sudo service php8.5-fpm reload
```

### PHP memory cap (max_memory_limit)

PHP 8.5's system-level `max_memory_limit` is 512M in `/etc/php/8.5/cli/php.ini`
(not editable without root). Backtests need up to 3G, so the cap is lifted in
user space: `/home/forge/php-overrides/99-pennyhunt.ini` sets
`max_memory_limit = 3G`, activated via `PHP_INI_SCAN_DIR=:/home/forge/php-overrides`
(leading colon = keep the default conf.d scan). That env var is set in:

- the `pennyhunt-horizon` and `pennyhunt-classify-backfill` systemd units
  (`Environment=` line),
- the forge crontab (top-of-file variable, inherited by `schedule:run`
  children).

Incident 2026-07-04: `RunBacktest` #33 died instantly — `ini_set('memory_limit',
'3072M')` over the cap raises a WARNING that Laravel promotes to
ErrorException. Code side is now guarded (`App\Support\Memory::raise()`
suppresses the warning and accepts the clamp), server side lifted as above.

### Environment

Shared `.env` at `/home/forge/pennyhunt.wecode.dev/.env`. Notable prod
settings: `APP_ENV=production`, `pgsql` on `127.0.0.1:5432`, Redis
queue/cache, `BROADCAST_CONNECTION=reverb` (PHP posts to `127.0.0.1:8080`),
`PENNYHUNT_ML_PYTHON=/home/forge/venvs/pennyhunt/bin/python`. The `APP_KEY`
matches local so restored encrypted data stays readable.

### Websockets (live UI updates)

**DONE 2026-07-04:** nginx proxies `location ~ ^/app/` to Reverb on
`127.0.0.1:8080` (added via Forge → site → Edit Nginx Configuration).
Verified: `wss://pennyhunt.wecode.dev/app/{key}` completes the HTTP/1.1
upgrade handshake (101). Note when testing with curl: force `--http1.1` —
over HTTP/2 there is no `Upgrade` header and Reverb answers 500, which is
expected and irrelevant to browsers (websockets always negotiate over
HTTP/1.1).

## Known gaps / follow-ups

1. **MySQL still runs** (Forge default) but is unused — can be disabled
   from the Forge UI to reclaim ~400 MB RAM.
2. Postgres backups: no automated dump yet. Add a nightly
   `pg_dump -Fc` cron to `~/backups` (and ideally off-box).
3. `REDDIT_CLIENT_ID/SECRET` and `FMP_API_KEY` are blank in prod (same as
   local) — Reddit flows through Apify, so nothing is broken.
