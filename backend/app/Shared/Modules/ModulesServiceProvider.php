<?php

declare(strict_types=1);

namespace App\Shared\Modules;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovers module service providers under app/Modules/* and registers them.
 *
 * Each module directory must contain a ServiceProvider class named
 * "{ModuleName}ServiceProvider" (e.g. TenancyServiceProvider) extending {@see Module}.
 */
final class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $files = $this->app->make(Filesystem::class);
        $modulesPath = base_path('app/Modules');

        if (! is_dir($modulesPath)) {
            return;
        }

        foreach ($files->directories($modulesPath) as $moduleDir) {
            $moduleName = basename($moduleDir);
            $providerClass = "App\\Modules\\{$moduleName}\\{$moduleName}ServiceProvider";

            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }
}
