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

### Reverb is a THIRD long-running process, and forgetting it degrades rather than breaks

`php artisan reverb:start` alongside the web server and Horizon. It needs its own
Supervisor program — Horizon does not start it and nothing else will.

```ini
[program:reverb]
process_name=%(program_name)s
command=php /path/to/backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/backend/storage/logs/reverb.log
```

⚠️ **Nothing fails when it is missing, which is why it needs a checklist line.**
The database is the source and the socket is an accelerator: with Reverb down a
message is still written, still read and still delivered — one refresh late.
There is no error, no failed job that names it, and no screen that says so. The
symptom is a support ticket about chat "feeling slow", weeks later.

Environment, and the split is deliberate: `REVERB_APP_SECRET` signs and never
leaves the server; `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT` and
`REVERB_SCHEME` are what the BROWSER is handed, so a requirement forbidding every
key in a payload cannot be satisfied and was never written.

```
BROADCAST_CONNECTION=reverb     # `log` in development — live delivery is OFF
REVERB_APP_ID=          REVERB_APP_KEY=        REVERB_APP_SECRET=
REVERB_HOST=            REVERB_PORT=443        REVERB_SCHEME=https
```

The reverse proxy must upgrade the WebSocket (`Upgrade`/`Connection` headers) on
whatever path `REVERB_HOST`/`REVERB_PORT` name, and TLS terminates there.

### `/api/broadcasting/auth` is inside the `api` group, and that is load-bearing

A middleware list handed to `withBroadcasting()` REPLACES the group rather than
adding to it — and what it drops is `EnsureCurrentWorkspace`, which pushes
spatie's team id. In team mode **no team id means no roles at all**, so every
private-channel subscription was answered `403` for teachers who read the same
thread over HTTP without trouble. No test could see it: `subscribeToChannel()`
posts from a process where `Sanctum::actingAs()` has already set the team id.

### The `community` queue needs a supervisor, and a queue with no worker says nothing

`config/horizon.php` names it in **both** `defaults` and `environments` — a queue
present only in `defaults` is one whose jobs enqueue and are never drained,
silently, with the dashboard showing nothing wrong. `FanOutAnnouncementJob` and
the offline-recipient notifications ride it; a hand-written `queue:work` whose
`--queue` list omits it produces the same silence (the `maintenance` lesson from
spec 019, reached from a second direction).

## Post-Deployment Checklist

- [ ] `.env` configured with production values (no debug, proper DB/Redis/Meilisearch)
- [ ] Migrations run (`php artisan migrate --force`)
- [ ] Super-admin created (check console output for password)
- [ ] Horizon running (check `/horizon`)
- [ ] Reverb running (`reverb:start` under Supervisor) — **nothing errors if it is not; chat just goes one refresh late**
- [ ] `BROADCAST_CONNECTION=reverb` and the four `REVERB_*` values set
- [ ] `supervisor-community` present in Horizon's `environments`, not only in `defaults`
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
- [ ] **Nineteen Arabic message templates submitted and approved.** Every message the
      platform starts must be an approved template: free-form text is permitted only inside
      the 24-hour window a user opens **by replying**, and we receive nothing, so that window
      never opens. Submit each with the name in `message_templates.type` and the body
      matching `body` — the numbered placeholders in the approved template must line up
      with `variables` **in order**, because that order is the only thing that says which
      parameter is which.
- [ ] ⚠️ **Approve `contact_verification` FIRST.** It carries the one-time code, so until it
      is approved no number on the platform can be verified — and the channel refuses to
      reach an unverified number, so approving the other eighteen first buys nothing at all.
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
leaving the platform to a third party. It is row `whatsapp` in `data_processors` since spec
013, declared `erasure_capability = none`: a message already delivered to a phone cannot be
unsent, and the provider keeps its own delivery log under its own policy. Saying anything
else there would put a sentence in an erasure report that is not true.

### Two settings spec 013 depends on, and both fail silently

- [ ] **Set `TRUSTED_PROXIES`** to the load balancer's addresses (comma-separated;
      `*` only where the balancer is the sole ingress and strips the header itself).
      `AppServiceProvider::trustConfiguredProxies()` reads it — deliberately there
      rather than in `bootstrap/app.php`, whose closure runs before the config
      repository is bound, and where `env()` is not a way round it because a cached
      config never loads `.env` at all.

      ⚠️ **Unset, the failure is not a missing record — it is a MISLEADING one.**
      `$request->ip()` returns the balancer's address, so every consent, every
      offboarding request and every data-rights request is stamped with the **same**
      address for every person on the platform. Those rows exist to be relied on in
      a dispute, and a column that is uniformly wrong reads exactly like a column
      that is right. The default is to trust nothing, never `'*'`: a wildcard makes
      `X-Forwarded-For` client-supplied, and a forgeable address is worse than an
      honest wrong one because it looks correct.

- [ ] **Set `SCOUT_QUEUE=true`** (it is the default in `config/scout.php`, which is
      itself a file that did not exist until 013 — so Scout's own default of `false`
      was in force). With it false, every index write runs INSIDE the request, and
      an erasure walking thousands of rows calls the search engine synchronously per
      model: the request times out half-way through an irreversible operation.
      `FR-023` is the other half — the index is the derived copy everyone forgets,
      `Model::search()` queries the engine outside every global scope, so a row
      deleted from MySQL still comes back in a search result until `unsearchable()`
      runs. `SCOUT_DRIVER=null` in tests means no local run ever sees either.

## Scaling Considerations

- **Database:** Read replicas for course catalog queries
- **Queue:** Multiple Horizon workers for certificate generation + notifications
- **Search:** Meilisearch cluster for large course catalogs
- **Frontend:** Deploy on Vercel or CDN-backed Node host
- **Media:** Use S3-compatible storage for receipts and certificate PDFs (configure `FILESYSTEM_DISK=s3`)
