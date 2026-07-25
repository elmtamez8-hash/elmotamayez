<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
        $this->app->singleton(WorkspaceScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Super Admin is a platform-level flag, not a tenant role, so it holds no
        // spatie permissions. BasePolicy::before() already lets it through policy
        // checks; this covers the bare `can('some.permission')` calls that Form
        // Requests use for authorization.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);

        // Module models live in App\Modules\{Module}\Models and their factories in
        // Database\Factories\Modules\{Module} — resolve that here instead of having
        // every model override newFactory().
        Factory::guessFactoryNamesUsing($this->guessFactoryName(...));
    }

    /**
     * Map a model to its factory class.
     *
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    private function guessFactoryName(string $model): string
    {
        $factory = preg_match('/^App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+)$/', $model, $matches) === 1
            ? "Database\\Factories\\Modules\\{$matches[1]}\\{$matches[2]}Factory"
            : 'Database\\Factories\\'.class_basename($model).'Factory';

        if (! is_subclass_of($factory, Factory::class)) {
            throw new RuntimeException("No factory found for model [{$model}] (looked for [{$factory}]).");
        }

        return $factory;
    }
}
