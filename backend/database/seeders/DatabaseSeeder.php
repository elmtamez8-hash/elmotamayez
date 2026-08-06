<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding must not require a running Meilisearch instance.
        // Run `php artisan scout:import` afterwards to index the seeded models.
        config(['scout.driver' => 'null']);

        $this->call([
            RolesAndPermissionsSeeder::class,
            // Before anything that can trigger a notification: a dispatch with no
            // template renders nothing and logs an error instead.
            NotificationTemplateSeeder::class,
            // Not a prerequisite — every value falls back to config() — but an
            // operational number nobody can see is one nobody ever tunes.
            PlatformSettingsSeeder::class,
        ]);

        // Only seed the super-admin in non-production environments.
        // In production, super-admins should be provisioned via a dedicated
        // artisan command or manual DB access with a strong generated password.
        if (! app()->environment('production')) {
            // SUPER_ADMIN_PASSWORD lets a developer keep one password across
            // re-seeds. Without it the password is random and printed once — which
            // is the right default, but it scrolls past in a long seed run and the
            // account is then only recoverable by resetting it.
            //
            // No fallback to a fixed string: an empty or missing variable must
            // generate a random password, never a guessable one. The env file is
            // not loaded in production anyway, and this whole block is skipped
            // there.
            $configured = trim((string) config('app.super_admin_password'));
            $password = $configured === '' ? Str::random(32) : $configured;

            User::factory()->create([
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'admin@example.com',
                'is_super_admin' => true,
                'password' => $password,
            ]);

            $this->command->info($configured === ''
                ? "Super Admin created. Email: admin@example.com Password: {$password}"
                : 'Super Admin created. Email: admin@example.com Password: from SUPER_ADMIN_PASSWORD');

            $this->call(DemoDataSeeder::class);
            $this->call(ScenarioSeeder::class);

            // Runs last: it seeds the shared taxonomy into every workspace, so the
            // workspaces have to exist first.
            $this->call(MarketplaceSeeder::class);
        }
    }
}
