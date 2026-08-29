<?php

declare(strict_types=1);

use Database\Seeders\RegionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T118 — the catalogue reaches a database that already exists.
 *
 * ⚠️ WITHOUT THIS FILE, EVERY NEW REGISTRATION ON A LIVE DATABASE IS A 422.
 * `region_slug` is required by `RegisterStudentRequest` and validated against
 * the rows in this table, so shipping the requirement without the rows closes
 * the front door of the product. The other three catalogues in this tree fail
 * silently; this one fails in front of the person signing up.
 *
 * `seedMissing()`, never `run()` — the seeder's own docblock says why: every row
 * is editable from `/admin`, and an overwrite in the deploy path resets an
 * operator's renaming on every release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RegionSeeder)->seedMissing();
    }

    /** Deliberately empty: removing the rows locks registration. */
    public function down(): void {}
};
