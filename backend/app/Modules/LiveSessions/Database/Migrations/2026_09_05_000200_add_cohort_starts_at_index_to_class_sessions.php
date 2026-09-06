<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index three cohort-timeline readers need (spec 027 · T001).
 *
 * ⚠️ `class_sessions_cohort_timeline_index (workspace_id, course_id, cohort_id,
 * starts_at)` ALREADY EXISTS AND NONE OF THEM CAN REACH IT. Every one of the
 * three names the cohort and not the two columns in front of it, and two of them
 * cannot name a workspace at all because they are platform reads that declare
 * `withoutWorkspaceScope()`:
 *
 *   1. `EloquentCohortScheduleDirectory::schedulePreviewFor()` — the public
 *      course page, which is DELIBERATELY uncached (`seats_left` is a snapshot,
 *      not a promise). Without this index it is a forward crawl of the
 *      platform-wide `starts_at` index, discarding every other teacher's
 *      sessions until it collects its limit — an unauthenticated read whose cost
 *      grows with the platform's total future session count rather than with the
 *      course being looked at. Spec 027 makes that page the funnel entry.
 *   2. `CohortScheduleDirectory::nextSessionFor()` — new here, `ORDER BY
 *      starts_at LIMIT 1`, fully ordered by this index.
 *   3. `ClaimSubscriptionSeats` — the auto-booking window read.
 *
 * ⚠️ `status` IS DELIBERATELY NOT IN THE INDEX. Most rows are `scheduled`, so it
 * is not selective; and putting it before `starts_at` would cost reader (2) its
 * ordering, which is the whole reason that read is one row instead of a sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index(['cohort_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex(['cohort_id', 'starts_at']);
        });
    }
};
