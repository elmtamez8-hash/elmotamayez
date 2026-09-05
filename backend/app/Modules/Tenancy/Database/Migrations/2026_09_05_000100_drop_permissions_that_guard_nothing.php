<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take three permissions off the roles screen, because nothing has ever read them.
 *
 * ⛔ **A PERMISSION NOBODY ASKS IS A TICK BOX THAT ENDS THE QUESTION.** All three
 * were declared, seeded, granted by `RolePermissionMatrix`, and composed into a
 * label on `/admin`'s role editor — and read by no `can()`, no policy, no
 * middleware and no Filament resource, in the backend or the frontend, by
 * constant or by literal. An owner unticking one changed nothing at all, which
 * is worse than a missing control: they believe they closed a door.
 *
 *   · `courses.archive` — archiving happens through the section batch, which
 *     `CoursePolicy` authorises with `courses.publish`.
 *   · `enrollments.create.manual` — there is no manual-enrolment door;
 *     `EnrollmentPolicy::create()` is an unconditional allow that no HTTP surface
 *     exercises, and `EnrollmentResource` registers only index and edit pages.
 *   · `settings.view` — the workspace settings screen is authorised by
 *     `WorkspacePolicy::update()`, which asks ownership and not a permission.
 *
 * ⚠️ THE FOUR `*.view.own` NAMES SURVIVE ON PURPOSE and are NOT in this list.
 * They are read by nothing either — but the thing they describe genuinely
 * happens: `EnrollmentController` and its siblings narrow to
 * `student_user_id = me` with an ownership branch, which is this repository's
 * stated pattern. They document a real answer reached another way. The three
 * above document capabilities that do not exist.
 *
 * ⚠️ THE NAMES ARE LITERALS HERE, NOT CONSTANTS. The constants are deleted in the
 * same change, and a migration that imports one is a migration that stops
 * running the moment the class is tidied — the file has to keep working against
 * a tree that no longer contains the thing it removes.
 *
 * ⚠️ AND `down()` CANNOT PUT THEM BACK MEANINGFULLY. Re-inserting the rows would
 * restore three tick boxes that guard nothing, so it restores exactly the defect
 * — the honest rollback is the same code with the constants returned to
 * `Permissions`, which is a code change and not a data one. It is written as a
 * no-op that says so rather than left to look forgotten.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NAMES = [
        'courses.archive',
        'enrollments.create.manual',
        'settings.view',
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::NAMES)
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        /*
        | The pivot first. Deleting the permission row while a `role_has_permissions`
        | row still points at it leaves an orphan that spatie's cache walks into on
        | the next `can()` — and on MySQL the foreign key would refuse the delete
        | anyway, aborting the migration halfway through a deploy.
        */
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Deliberately nothing — see the class docblock. Restoring the rows
        // restores three controls that guard nothing, which is the thing being
        // removed rather than the state being rolled back to.
    }
};
