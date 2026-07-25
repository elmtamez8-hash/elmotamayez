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
        ]);

        // Only seed the super-admin in non-production environments.
        // In production, super-admins should be provisioned via a dedicated
        // artisan command or manual DB access with a strong generated password.
        if (! app()->environment('production')) {
            $password = Str::random(32);

            User::factory()->create([
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'admin@example.com',
                'is_super_admin' => true,
                'password' => $password,
            ]);

            $this->command->info("Super Admin created. Email: admin@example.com Password: {$password}");

            $this->call(DemoDataSeeder::class);
            $this->call(ScenarioSeeder::class);
        }
    }
}
