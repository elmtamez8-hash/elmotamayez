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
            // Reference data for the same reason the templates above are: the
            // launch default is PREPAID_CREDITS, so an empty catalogue is not an
            // empty screen — it is a student who cannot buy and therefore cannot
            // book, on every fresh install.
            CreditPackageSeeder::class,
            // Reference data on the same footing: AwardPoints returns silently for
            // an action with no row, so an empty catalogue is a gamification
            // system that is switched on, reports success, and awards nothing.
            GamificationCatalogSeeder::class,
            // Spec 013, on the same footing again: the consent screen, the nightly
            // retention sweep and the schema-coverage guard all read this table,
            // and all three do nothing at all against an empty one — quietly.
            DataCategorySeeder::class,
            // Who receives data outside our own servers (FR-024). Two of the six
            // rows are the video and broadcast providers, which carry a child's
            // voice and face — the register is the answer to the question a parent
            // asks first.
            DataProcessorSeeder::class,
            // Spec 011 · FR-042, and the sharpest of the runtime catalogues: the
            // registration form REQUIRES a region and validates it against these
            // rows, so an empty table refuses every new account in front of an
            // empty picker. The other three fail silently; this one fails loudly
            // at the front door.
            RegionSeeder::class,
            // Spec 022 · FR-001 — the subjects, the broad stages and the school
            // years. It lived inside `MarketplaceSeeder` until now, and that
            // seeder is called only outside production (see the bottom of this
            // method): a production database was therefore born with an empty
            // taxonomy, so the teacher application offered nothing to pick and
            // the FIRST teacher on the platform could never apply. Unconditional
            // here, for the same reason the four catalogues above it are.
            TaxonomySeeder::class,
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

            // Runs last: the demo marketplace it seeds needs the workspaces and
            // their teachers to exist first. (The taxonomy itself is platform-wide
            // since spec 009 and no longer depends on any workspace.)
            $this->call(MarketplaceSeeder::class);

            // Last, and after the demo teacher exists: platform standing is a row
            // added to somebody who is already there. `platform_staff` was empty on
            // every development database until now, which left the officer's half
            // of spec 027's walk blocked on absent data rather than on code.
            $this->call(PlatformStaffSeeder::class);
        }
    }
}
