<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            $this->dropMachineWritten((int) $orphan->id);
        });
    }

    /**
     * Rows nobody authored: deleted with the workspace, never moved.
     *
     * ⚠️ THE CATEGORY IS «WRITTEN BY MACHINERY, OWNED BY NOBODY», and each member
     * of it is named with its evidence rather than pattern-matched. Everything
     * outside this method still stops the migration, which is the point: FR-024
     * makes proving the workspace empty part of the requirement, and a silent
     * delete of something a person typed would be the opposite of that.
     *
     * ⚠️ AND THE LIST GREW WHEN IT MET A REAL DATABASE. Production measured four
     * tables in the orphan; a developer's database had six. The two extra ones
     * are below — the throw did its job and named them, which is how they were
     * found at all.
     */
    private function dropMachineWritten(int $orphanId): void
    {
        /*
        | Derived nightly, and the `workspace_id = 0` sentinel row is the platform
        | total. Carrying these into a teacher's workspace would double a number
        | in the platform report with nothing anywhere to say why.
        */
        DB::table('platform_metrics_daily')->where('workspace_id', $orphanId)->delete();

        /*
        | The six default terms `SeedDefaultBlockedTerms` writes on
        | `WorkspaceCreated` — measured identical, with identical timestamps, in
        | every workspace on the database. They are moderation vocabulary for a
        | workspace that has zero members and zero roles, so no screen has ever
        | reached them and nobody typed them. Moving them would give one teacher a
        | second copy of the list they already have.
        |
        | ⚠️ A term somebody ADDED by hand would be indistinguishable here, and
        | that is a real limit — it is accepted because the orphan is unreachable:
        | with no members and no roles there is no door to the moderation screen.
        */
        DB::table('blocked_terms')->where('workspace_id', $orphanId)->delete();

        /*
        | The «غير مصنّف» fallback concept every workspace gets automatically.
        |
        | ⚠️ THREE CONDITIONS, AND THE THIRD IS THE ONE THAT MATTERS. No subject
        | and no creator identify it as auto-created rather than authored; the
        | reference check is what stops this deleting a concept that questions,
        | masteries or study rooms still point at. Anything failing them stays,
        | and the delete migration then refuses — which is the correct outcome,
        | because a concept with dependants is somebody's teaching material.
        */
        $fallbacks = DB::table('concepts')
            ->where('workspace_id', $orphanId)
            ->whereNull('subject_id')
            ->whereNull('created_by')
            ->pluck('id');

        foreach ($fallbacks as $conceptId) {
            $referenced = collect(['questions', 'concept_stats', 'concept_masteries', 'adaptive_sessions', 'study_rooms'])
                ->contains(fn (string $table): bool => Schema::hasColumn($table, 'concept_id')
                    && DB::table($table)->where('concept_id', $conceptId)->exists());

            if (! $referenced) {
                DB::table('concepts')->where('id', $conceptId)->delete();
            }
        }
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
