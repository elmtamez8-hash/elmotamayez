<?php

declare(strict_types=1);

namespace App\Shared\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * Base class for each module's service provider.
 *
 * A module is a self-contained bounded context. Each module ships its own:
 * Models, Actions, DTOs, Policies, Events, Listeners, Http (Controllers/Requests/Resources),
 * Database (migrations/factories/seeders) and routes.
 *
 * Concrete providers extend this and call {@see registerRoutes()},
 * {@see registerMigrations()} and {@see registerFactories()} from their boot() method.
 */
abstract class Module extends ServiceProvider
{
    /**
     * The module's short name (e.g. "Tenancy"). Must match the directory name.
     */
    protected string $name;

    public function register(): void
    {
        $this->callAfterResolving('router', function (): void {
            $this->registerRoutes();
        });
    }

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerFactories();
    }

    /**
     * Load the module's API and web route files, if present.
     * API routes are prefixed with /api/v1 and namespaced under App\Modules\{Name}\Http\Controllers.
     */
    protected function registerRoutes(): void
    {
        $namespace = "App\\Modules\\{$this->name}\\Http\\Controllers";

        $apiFile = $this->modulePath('routes/api.php');
        if (is_file($apiFile)) {
            $this->app['router']->group([
                'prefix' => 'api/v1',
                'namespace' => $namespace,
                'middleware' => ['api'],
            ], $apiFile);
        }

        $webFile = $this->modulePath('routes/web.php');
        if (is_file($webFile)) {
            $this->app['router']->group([
                'namespace' => $namespace,
                'middleware' => ['web'],
            ], $webFile);
        }
    }

    protected function registerMigrations(): void
    {
        // Directory name must match the one modules ship on disk exactly:
        // case-insensitive filesystems (Windows/macOS) hide a mismatch that would
        // silently load zero migrations on Linux.
        $migrationsPath = $this->modulePath('Database/Migrations');

        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }

    protected function registerFactories(): void
    {
        $factoriesPath = $this->modulePath('Database/factories');

        if (is_dir($factoriesPath)) {
            $this->loadFactoriesFrom($factoriesPath);
        }
    }

    protected function modulePath(string $path = ''): string
    {
        $base = base_path("app/Modules/{$this->name}");

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    public function name(): string
    {
        return $this->name;
    }
}
