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

## Scaling Considerations

- **Database:** Read replicas for course catalog queries
- **Queue:** Multiple Horizon workers for certificate generation + notifications
- **Search:** Meilisearch cluster for large course catalogs
- **Frontend:** Deploy on Vercel or CDN-backed Node host
- **Media:** Use S3-compatible storage for receipts and certificate PDFs (configure `FILESYSTEM_DISK=s3`)
