# Deployment Guide

## Prerequisites

- PHP 8.5+ with extensions: pdo, mbstring, xml, bcmath, ctype, json, openssl, tokenizer, fileinfo, pcntl (for Horizon)
- Composer 2.x
- Node.js 20+ and npm
- MySQL 8+ (production) or SQLite (development)
- Redis 6+ (production: cache, queue, sessions)
- Meilisearch 1.x (production: full-text search)
- Nginx or Apache (web server)

## Docker Deployment (Recommended)

### Using Docker Compose

```bash
# Build and start all services
docker-compose -f docker/docker-compose.yml up -d --build

# Run migrations
docker-compose exec backend php artisan migrate --force

# Seed production data (super-admin only)
docker-compose exec backend php artisan db:seed --force

# Generate API docs
docker-compose exec backend php artisan scribe:generate
```

### Services
| Service | Port | Description |
|---|---|---|
| backend (PHP-FPM) | 9000 | Laravel API |
| frontend (Node) | 3000 | Next.js SSR |
| nginx | 80/443 | Reverse proxy |
| mysql | 3306 | Database |
| redis | 6379 | Cache + Queue |
| meilisearch | 7700 | Search engine |
| horizon | — | Queue dashboard at `/horizon` |
| admin | /admin | Filament admin panel |

## Manual Deployment

### Backend

```bash
cd backend

# Install dependencies (no dev packages in production)
composer install --optimize-autoloader --no-dev

# Environment
cp .env.example .env
# Edit .env with production values:
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://your-domain.com
#   DB_CONNECTION=mysql
#   DB_HOST=...
#   CACHE_STORE=redis
#   QUEUE_CONNECTION=redis
#   SESSION_DRIVER=redis
#   SCOUT_DRIVER=meilisearch
#   SCOUT_MEILISEARCH_HOST=http://127.0.0.1:7700

php artisan key:generate
php artisan migrate --force
php artisan db:seed --force  # Only first deploy (creates super-admin)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan scribe:generate
```

### Frontend

```bash
cd frontend
npm ci
npm run build
npm start  # Or use PM2 / systemd
```

### Queue Worker (Horizon)

```bash
php artisan horizon  # Production: use Supervisor
```

Supervisor config (`/etc/supervisor/conf.d/horizon.conf`):
```ini
[program:horizon]
process_name=%(program_name)s
command=php /path/to/backend/artisan horizon
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/backend/storage/logs/horizon.log
```

## Post-Deployment Checklist

- [ ] `.env` configured with production values (no debug, proper DB/Redis/Meilisearch)
- [ ] Migrations run (`php artisan migrate --force`)
- [ ] Super-admin created (check console output for password)
- [ ] Horizon running (check `/horizon`)
- [ ] Meilisearch running and indexed (`php artisan scout:sync-index-settings`)
- [ ] API docs generated (`/docs`)
- [ ] SSL/TLS configured (Let's Encrypt or similar)
- [ ] Firewall: only expose 80/443, keep 3306/6379/7700 internal
- [ ] Backup strategy for MySQL (daily dumps)
- [ ] Log rotation configured (`storage/logs/laravel.log`)
- [ ] `storage/` and `bootstrap/cache/` writable by web server

### The two CDN settings that can only be set once a production domain exists

Both live in the Bunny Stream library (Stream ▸ mteatch ▸ Security ▸ General). Neither can
be set from `.env`, and neither shows a failure anywhere in our logs — a wrong value 403s at
the CDN and the player reports a generic error.

- [ ] **`Allowed domains`** — deliberately **empty** until launch, because an empty list means
      "any referrer passes" and a list containing the wrong host refuses every video for every
      student. When you fill it, add the production host **and** `localhost:3000` /
      `127.0.0.1:3000` in the same edit, or local development and the Playwright suite die at
      the CDN with the refusal visible only there. Note what it buys: the referrer header is
      forged with one `curl` flag, so this is hotlink/bandwidth protection, **not** access
      control — access control is the HMAC playback token, which is already on.
- [ ] **`Referrer-Policy`** — leave Next's default (`strict-origin-when-cross-origin`). Bunny's
      "block direct URL file access" is ON, which refuses any request carrying **no** `Referer`
      at all. Setting `Referrer-Policy: no-referrer` anywhere in the frontend therefore refuses
      every video for every student. Measured 2026-08-18: a correctly signed URL is `403` from
      `curl` with no referrer and `200` with any referrer whatsoever.

There is **no CORS setting to configure**. A Stream library exposes none, and its pull zone
(`vz-…`) is managed internally and does not appear under CDN. It answers
`Access-Control-Allow-Origin: *` on the master playlist, the rendition playlist and the `.ts`
segments — measured 2026-08-18 — which is what `hls.js` needs, since it reads the manifest
with `XMLHttpRequest`. Without it Safari's native HLS would be the only browser that plays
anything, and nothing would name the cause.

### The WhatsApp channel — three human processes before one message is sent

Spec 020 ships the code. None of it can deliver anything until a person completes the
following, and **none of these steps is something the application can do or check for
itself**. Until they are done the channel answers `isEnabled()` false, every delivery is
recorded `skipped`, and the product behaves exactly as it did before — which is the
intended behaviour of an unconfigured deployment, not a fault.

- [ ] **A BSP account and a verified Meta business.** Verification is Meta's process and is
      measured in days. The account provider we build against fronts Meta's Cloud API with a
      request body identical byte for byte, which is why moving to Meta direct later is two
      env values (`WHATSAPP_BASE_URL`, `WHATSAPP_AUTH_HEADER`) and no code.
- [ ] **A dedicated phone number** attached to the WhatsApp Business account. It cannot be a
      number already in use on the consumer WhatsApp app.
- [ ] **Eighteen Arabic message templates submitted and approved.** Every message the
      platform starts must be an approved template: free-form text is permitted only inside
      the 24-hour window a user opens **by replying**, and we receive nothing, so that window
      never opens. Submit each with the name in `message_templates.type` and the body
      matching `body_ar` — the numbered placeholders in the approved template must line up
      with `variables` **in order**, because that order is the only thing that says which
      parameter is which.
- [ ] ⚠️ **Approve `contact_verification` FIRST.** It carries the one-time code, so until it
      is approved no number on the platform can be verified — and the channel refuses to
      reach an unverified number, so approving the other seventeen first buys nothing at all.
- [ ] **Flip `provider_approval_status` to `approved`** on each row as its approval lands
      (`/admin` ▸ Message Templates). The seeder ships every WhatsApp row `pending` on
      purpose: claiming an approval that has not happened turns the first send into a
      provider error code nobody can map back to a row, instead of a refusal that names the
      template key in Arabic.
- [ ] **Set the env block** (`WHATSAPP_ENABLED`, `WHATSAPP_BASE_URL`, `WHATSAPP_AUTH_HEADER`,
      `WHATSAPP_API_KEY`) — see `.env.example`. The key is env and **never** a
      `platform_settings` row: that table is readable by anyone who can open the admin panel,
      so a sending key there widens who can message a parent from "whoever runs the server"
      to "whoever runs a workspace".
- [ ] **Send one real message to one real phone.** A green suite proves the code agrees with
      our own fake of the provider; spec 017's first live run found five defects that no test
      had seen.

**The provider is a data processor.** It receives a student's name, their attendance, their
guardian's phone number, and in some templates a balance — that is, a minor's personal data
leaving the platform to a third party. It belongs in the processor registry that spec 013
builds; until that phase lands, this line is the register. Postponing the phase does not
postpone knowing who holds the data.

## Scaling Considerations

- **Database:** Read replicas for course catalog queries
- **Queue:** Multiple Horizon workers for certificate generation + notifications
- **Search:** Meilisearch cluster for large course catalogs
- **Frontend:** Deploy on Vercel or CDN-backed Node host
- **Media:** Use S3-compatible storage for receipts and certificate PDFs (configure `FILESYSTEM_DISK=s3`)
