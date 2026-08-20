<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Date of birth, and what depends on knowing it (spec 013 · FR-009 · R7).
 *
 * ONE migration, not two: the columns, the missing profile rows and the backfill
 * all have to be true together before anything reads them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ⚠️ `date_of_birth` IS NULLABLE AND NEVER BECOMES `NOT NULL`. Three independent
 * reasons, and the third is the one that would do real damage:
 *
 *  1. NO SOURCE FILLS EVERY ROW. The only existing evidence of a student's age is
 *     `parent_student_relations.student_age`, and a student who registered
 *     themselves has no row there at all. The repository's own rule is written in
 *     a shipped migration: "a constraint the existing rows cannot satisfy is not a
 *     stronger guarantee — it is a deploy that fails".
 *
 *  2. `->change()` IN LARAVEL 13 RE-DECLARES THE COLUMN, dropping every attribute
 *     not restated, and converting to NOT NULL REBUILDS THE TABLE on SQLite.
 *     `student_profiles` carries `unique('user_id')`, and if that were lost one
 *     user could hold two profile rows — with NO TEST IN THE TREE asserting the
 *     index exists. Spec 016 met the same choice and wrote: "a table rebuilt and
 *     silently missing an index is a worse trade than a constraint the model
 *     already enforces".
 *
 *  3. NULL IS THE REPRESENTATION OF `FR-009ج` — "date of birth unknown", which is
 *     a real state the product must handle. With NOT NULL the code needs a
 *     placeholder date, AND A PLACEHOLDER DATE IS A BIRTHDAY: it reaches eighteen
 *     on a schedule and fires a real ownership transfer for a person whose age
 *     nobody knows.
 *
 * The guard is therefore in the Action — `RegisterStudent` requires it, and
 * `ActivateStudentAccount` refuses without it.
 * ══════════════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('user_id');

            /*
            | ⚠️ NULLABLE WITH NO `default`, AND THE ABSENCE OF THE DEFAULT IS THE
            | POINT. `default(true)` applies to every later INSERT too, so a
            | student who typed their real birth date would be marked as an
            | estimate and have `FR-009ج`'s fallback applied to them for no reason.
            | Three states: true (derived from a guardian's stated age), false
            | (the person told us), null (neither, i.e. an old row).
            */
            $table->boolean('dob_is_estimated')->nullable()->after('date_of_birth');

            /*
            | ⚠️ WITHOUT THIS COLUMN THE COMING-OF-AGE NOTICE REPEATS EVERY NIGHT
            | FOR EVER. A predicate of the form `date_of_birth <= today − 18y` is
            | true again tomorrow, so the sweep would re-notify every adult on the
            | platform nightly. It is the `notified_dormant_at` defect exactly, and
            | its rule comes with it: STAMP BEFORE SENDING, because a lost notice
            | is better than one a night for the rest of someone's life.
            */
            $table->timestamp('ownership_transferred_at')->nullable();

            /*
            | The contact the student gave for their guardian at self-registration.
            |
            | ⚠️ IT IS STORED BECAUSE `parent_student_relations.guardian_user_id` IS
            | `NOT NULL` — so when the named guardian has no account yet, there is
            | no relation row to create and nothing to notify. Discarding what the
            | student typed would leave a blocked account with no record of who was
            | supposed to unblock it. With an existing account, the relation IS the
            | invitation and this column is merely what it was matched on.
            */
            $table->string('guardian_contact')->nullable();

            /*
            | ⚠️ THE NULL COLUMN LEADS, and that is what makes the nightly sweep
            | cheap as the platform grows: everyone already transferred is excluded
            | by the first column, so the range scan on the date only ever walks
            | the shrinking remainder.
            */
            $table->index(['ownership_transferred_at', 'date_of_birth']);
        });

        $this->createMissingProfiles();
        $this->backfillDatesOfBirth();
    }

    /**
     * ⚠️ SPEC 004's MIGRATION ONLY CREATED PROFILES FOR STUDENTS WHO HAD A GRADE
     * LEVEL OR WERE REGISTERED BY A PARENT — so an older self-registered student
     * has no `student_profiles` row at all. `SC-017` is stated over "student
     * accounts", and an assertion made against `student_profiles` would be
     * VACUOUSLY TRUE for exactly the students the criterion is about.
     */
    private function createMissingProfiles(): void
    {
        DB::table('users')
            ->where('platform_role', 'student')
            ->whereNotIn('id', fn ($query) => $query->select('user_id')->from('student_profiles'))
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();

                DB::table('student_profiles')->insert(
                    $users->map(fn ($user): array => [
                        'user_id' => $user->id,
                        'registered_by_parent' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });
    }

    /**
     * ⚠️ `MIN(student_age)`, NOT AN ARBITRARY ONE.
     *
     * `parent_student_relations` is unique on (guardian, student), so ONE student
     * can carry several stated ages written by different people — and
     * `UPDATE … JOIN` picks among them non-deterministically on MySQL. A single
     * year at the eighteen boundary is the difference between someone who signs
     * for themselves and someone who legally cannot, so the tie is broken toward
     * the MINOR every time.
     *
     * ⚠️ AND `chunkById`, NEVER `chunk`. The predicate (`date_of_birth IS NULL`)
     * shrinks as the walk fixes rows, and `chunk` paginates by OFFSET — so every
     * page after the first skips as many rows as the previous page repaired, AND
     * REPORTS SUCCESS. The same defect that cost spec 016's uuid backfill a fix.
     */
    private function backfillDatesOfBirth(): void
    {
        DB::table('student_profiles')
            ->whereNull('date_of_birth')
            ->orderBy('id')
            ->chunkById(500, function ($profiles): void {
                $ages = DB::table('parent_student_relations')
                    ->whereIn('student_user_id', $profiles->pluck('user_id'))
                    ->whereNotNull('student_age')
                    ->groupBy('student_user_id')
                    ->pluck(DB::raw('MIN(student_age)'), 'student_user_id');

                foreach ($profiles as $profile) {
                    $age = $ages[$profile->user_id] ?? null;

                    if ($age === null) {
                        continue;
                    }

                    DB::table('student_profiles')->where('id', $profile->id)->update([
                        // An age is a year, not a date. Anchoring on today's
                        // month and day is a guess and is marked as one below.
                        'date_of_birth' => CarbonImmutable::now()->subYears((int) $age)->toDateString(),
                        'dob_is_estimated' => true,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropIndex(['ownership_transferred_at', 'date_of_birth']);
            $table->dropColumn([
                'date_of_birth',
                'dob_is_estimated',
                'ownership_transferred_at',
                'guardian_contact',
            ]);
        });
    }
};
