<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ `platform_role` DECIDES WHAT THE SIDEBAR OFFERS NOW, AND FIFTEEN LIVE
 * ACCOUNTS HAD NONE.
 *
 * The column arrived in `2026_08_01_000200_add_platform_profile_to_users` with
 * its own docblock saying so: «Null = account created through the existing
 * academy-signup path, which this feature leaves untouched». That was true while
 * nothing read it. It stopped being true the moment `isLearner()` started
 * deciding whether «تعلّمي», «رصيدي», «دفتر أخطائي» and eleven more appear —
 * because a null reads as «not a learner», and a student who registered before
 * that date would sign in to a sidebar with their own screens missing.
 *
 * Measured on production 2026-09-03 before writing this: 15 accounts with a null
 * role, of which **8 hold a course enrolment**. Not a hypothetical.
 *
 * ⚠️ THE PREDICATE IS EVIDENCE, NEVER A GUESS. A role written for somebody who
 * never said it is invented data — the reason spec 022 refused to backfill
 * `school_year_slug` from `grade_level_slug`. So the only accounts touched are
 * those the database already answers for: an enrolment or an order says «this
 * person buys and studies», a guardian relation says «this person watches a
 * child». An account with no footprint at all is left null, deliberately: there
 * is nothing here that knows what they are.
 *
 * ⚠️ AND THE ORDER MATTERS. `student` is claimed first and `parent` only fills
 * what is still null, so a guardian who also studies keeps the role their own
 * enrolment earned. Both are on the learning side, so the sidebar agrees either
 * way — but `homePathFor` does not, and sending a paying student to somebody
 * else's landing page is a different screen, not a different label.
 *
 * ⚠️ THE THREE EXCLUSIONS ARE COLUMNS, NOT ROLE NAMES. `is_super_admin` is the
 * platform flag, `teacher_profiles` is the row a marketplace teacher owns, and
 * `workspaces.owner_user_id` is the founder — spatie's `model_has_roles` would
 * have needed the morph string and would still have missed the founder, whose
 * standing is a column on `workspaces`. One of the five accounts left null on
 * production is a teacher with zero enrolments and a profile row; the exclusion
 * is what keeps her out.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('platform_role')
            ->where('is_super_admin', false)
            ->where(function ($query): void {
                // An order without an enrolment is the ordinary shape of a
                // student whose transfer has not been approved yet: the
                // enrolment is written by `CreateEnrollmentFromOrder`, a
                // listener that has not run. Reading only enrolments would
                // classify exactly the person waiting on us.
                $query
                    ->whereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('enrollments')
                        ->whereColumn('enrollments.student_user_id', 'users.id'))
                    ->orWhereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.user_id', 'users.id'));
            })
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('teacher_profiles')
                ->whereColumn('teacher_profiles.user_id', 'users.id'))
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('workspaces')
                ->whereColumn('workspaces.owner_user_id', 'users.id'))
            ->update(['platform_role' => 'student']);

        DB::table('users')
            ->whereNull('platform_role')
            ->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('parent_student_relations')
                ->whereColumn('parent_student_relations.guardian_user_id', 'users.id'))
            ->update(['platform_role' => 'parent']);
    }

    /**
     * ⚠️ THIS CANNOT BE UNDONE, AND SAYING SO IS THE POINT.
     *
     * Rolling back would mean nulling `platform_role` for every student and
     * guardian on the platform — including the ones `RegisterStudent` stamped
     * itself, which this migration never touched. There is no column recording
     * which rows were written here, and adding one to carry a rollback nobody
     * will run is a column that outlives its reason. The `down()` of
     * `_000600_drop_exam_id_from_questions` says the same thing for the same
     * reason: a reversal that restores the wrong state is worse than none.
     */
    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
