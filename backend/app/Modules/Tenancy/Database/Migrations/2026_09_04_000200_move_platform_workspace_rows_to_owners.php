<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 025 · FR-024, first half — empty the orphan workspace into the workspaces
 * that became correct the moment FR-001 shipped.
 *
 * ⚠️ THIS MIGRATION CANNOT BE ROLLED BACK, and `down()` says so rather than
 * pretending. It also cannot run before the backfill: a row only has somewhere to
 * go once its owner has a workspace, which is why the filename sorts after
 * `..._000100_backfill_implicit_teacher_workspaces`.
 *
 * ⚠️ AND IT IDENTIFIES THE ORPHAN BY `owner_user_id IS NULL`, NEVER BY SLUG. The
 * slug came from `env('MARKETPLACE_PLATFORM_WORKSPACE', 'platform')` — an
 * environment variable this spec DELETES along with the config block, so a
 * migration reading `config(...)` gets null on the next `migrate:fresh`,
 * `where('slug', null)` matches nothing, and the whole thing reports success
 * having moved not one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphan = DB::table('workspaces')->whereNull('owner_user_id')->first();

        // Nothing to do — the ordinary case on a fresh database, and on every
        // `RefreshDatabase` run in the test suite. Returning quietly here is what
        // keeps this migration from reddening a suite it has no business touching.
        if ($orphan === null) {
            return;
        }

        /*
        | ⚠️ ONE TRANSACTION, OPENED BY HAND. Laravel does not wrap migrations —
        | `Schema\Grammars\Grammar::$transactions` is false and only Postgres and
        | SqlServer override it. Without this, a throw after the derived rows are
        | deleted commits that deletion and leaves the move half done, and the
        | next attempt measures a different database than the one it was written
        | for.
        */
        DB::transaction(function () use ($orphan): void {
            $this->moveApplications((int) $orphan->id);
            $this->moveProfiles((int) $orphan->id);
            $this->moveAvailability((int) $orphan->id);

            /*
            | ⛔ DERIVED, SO DELETED — NOT MOVED. `platform_metrics_daily` is
            | recomputed nightly and its `workspace_id = 0` sentinel row is the
            | platform total. Carrying these rows into a teacher's workspace would
            | double a number in the platform report with nothing anywhere to say
            | why.
            */
            DB::table('platform_metrics_daily')->where('workspace_id', $orphan->id)->delete();
        });
    }

    /**
     * The application follows its own author.
     */
    private function moveApplications(int $orphanId): void
    {
        foreach (DB::table('teacher_applications')->where('workspace_id', $orphanId)->get() as $row) {
            DB::table('teacher_applications')
                ->where('id', $row->id)
                ->update(['workspace_id' => $this->workspaceOwnedBy((int) $row->user_id, 'teacher_applications', (int) $row->id)]);
        }
    }

    /**
     * The profile follows its own author — and takes marketplace participation
     * with it, but only if this teacher was actually listed before the move.
     */
    private function moveProfiles(int $orphanId): void
    {
        foreach (DB::table('teacher_profiles')->where('workspace_id', $orphanId)->get() as $row) {
            $target = $this->workspaceOwnedBy((int) $row->user_id, 'teacher_profiles', (int) $row->id);

            DB::table('teacher_profiles')->where('id', $row->id)->update(['workspace_id' => $target]);

            /*
            | ⚠️ THE EDGE THAT DROPS A TEACHER OFF THE MARKETPLACE WITH NO ERROR
            | ANYWHERE. `is_publicly_listed` is derived from (approved × workspace
            | participates). The orphan carried `participates = 1`; a freshly born
            | workspace carries the column's default, `false`. So moving an
            | approved, listed profile into it silently unlists them — the teacher
            | simply stops appearing, and nothing logs a thing. Only the profiles
            | that were ALREADY listed are stamped: participation stays an opt-in
            | (001 · FR-001) for everyone else.
            */
            if ((bool) $row->is_publicly_listed) {
                DB::table('workspaces')->where('id', $target)->update(['participates_in_marketplace' => true]);
            }
        }
    }

    /**
     * A slot has no user of its own — it follows the profile it belongs to, which
     * is why the profiles must have moved first.
     */
    private function moveAvailability(int $orphanId): void
    {
        foreach (DB::table('availability_slots')->where('workspace_id', $orphanId)->get() as $row) {
            $target = DB::table('teacher_profiles')
                ->where('id', $row->teacher_profile_id)
                ->value('workspace_id');

            if ($target === null) {
                throw new RuntimeException(
                    "availability_slots#{$row->id} points at teacher_profile#{$row->teacher_profile_id}, which does not exist. "
                    .'Refusing to continue: the next migration deletes this workspace and the deletion cannot be undone.'
                );
            }

            DB::table('availability_slots')->where('id', $row->id)->update(['workspace_id' => $target]);
        }
    }

    /**
     * The workspace a user OWNS — and the join is `workspaces.owner_user_id`,
     * never `users.last_workspace_id`.
     *
     * ⚠️ That column is written by `AcceptInvitation` and `SwitchWorkspace` too,
     * so for anyone who is a member at another teacher it names THAT OTHER
     * TEACHER'S workspace. Dropping a `teacher_profiles` row there hands the
     * profile to them through `WorkspaceScope`, and `TeacherProfilePolicy` is
     * permission-based, so they read and edit it as their own. Production carries
     * the shape live: user #30 holds an approved, listed profile and is a member
     * of somebody else's workspace.
     *
     * ⚠️ AND A ROW WITH NOWHERE TO GO STOPS THE WHOLE MIGRATION. The next one
     * deletes this workspace permanently; FR-024 makes proving it empty part of
     * the requirement, not a precaution, so a row that cannot be placed is a
     * throw and never a skip.
     */
    private function workspaceOwnedBy(int $userId, string $table, int $rowId): int
    {
        $workspaceId = DB::table('workspaces')->where('owner_user_id', $userId)->value('id');

        if ($workspaceId === null) {
            throw new RuntimeException(
                "{$table}#{$rowId} belongs to user#{$userId}, who owns no workspace. "
                .'The backfill migration should have created one — refusing to continue, '
                .'because the next migration deletes this workspace and cannot undo it.'
            );
        }

        return (int) $workspaceId;
    }

    /**
     * ⚠️ THIS CANNOT BE UNDONE, and saying so is the point.
     *
     * The rows carried no record of where they came from, so a `down()` would
     * have to invent one — and the derived metrics are gone outright. A rollback
     * that re-creates an empty workspace and moves nothing reads as a restore and
     * restores nothing, which is worse than refusing.
     */
    public function down(): void {}
};
