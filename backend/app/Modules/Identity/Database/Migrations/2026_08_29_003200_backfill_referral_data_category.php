<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · US3 — the `referral_record` category reaches a live database.
 *
 * ⚠️ SHIPPED WITH THE TABLES, BECAUSE NOTHING WOULD HAVE TOLD US OTHERWISE.
 * `PersonalDataContractCoverageTest` is a per-MODULE guard and `Identity` was
 * already covered, so `referrals` and `referral_codes` — two tables holding two
 * people's identities and the link between them — could land inside it with the
 * whole suite green. That limitation is recorded in `docs/README.md`, and this is
 * the first change made after it was written down.
 *
 * `DataCategorySeeder::run()` is `firstOrCreate` per row (see the seeder), so
 * this adds what is missing and rewrites nothing an operator tuned.
 *
 * `down()` is empty on purpose: dropping the row would leave both tables in the
 * database with no category describing them, which is the uncovered state the
 * coverage test exists to refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    public function down(): void {}
};
