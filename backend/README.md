# Mteatch Backend — Laravel API

Educational multi-tenant SaaS platform built as a modular monolith on Laravel 13 / PHP 8.5.

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

## API

The API is available at `http://localhost:8000/api/v1` with 67+ endpoints across 10 modules.

Generate interactive API docs:
```bash
php artisan scribe:generate
# Visit http://localhost:8000/docs
```

## Admin Panel

Filament v5 admin panel at `http://localhost:8000/admin` with resources for:
- Courses (create/edit/delete, status management)
- Orders (status tracking, approval workflow)
- Enrollments (progress monitoring)
- Exams (configuration, publishing)

## Queue Monitoring

Horizon dashboard at `http://localhost:8000/horizon` (requires Redis + pcntl).

## Testing

```bash
php vendor/bin/pest                    # 100 tests
php vendor/bin/pest --coverage         # With coverage
./vendor/bin/pint                      # Fix PSR-12 style
./vendor/bin/pint --test               # Check style
./vendor/bin/phpstan analyse           # Larastan level 8
```

## Default Users (after seeding)

| Role | Email | Password |
|---|---|---|
| Super Admin | admin@example.com | (printed in console) |
| Teacher | teacher@example.com | password |
| Student | student@example.com | password |

## Architecture

- **Modular monolith** — modules in `app/Modules/{Module}/`
- **Multi-tenancy** — single DB + `workspace_id` global scope
- **Auth** — Sanctum bearer tokens (API) + session (Filament admin)
- **Permissions** — spatie/permission in team mode
- **Search** — Meilisearch via Laravel Scout
- **Media** — spatie/laravel-medialibrary (receipts, certificate PDFs)
- **Activity log** — spatie/laravel-activitylog

See `docs/` for ERD, deployment guide, and architecture overview.
