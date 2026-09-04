<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Spec 025 · FR-015 — every teacher who registered before the workspace was born
 * with the account gets theirs now.
 *
 * ⚠️ WHY THIS RUNS BEFORE THE DOOR CLOSES, AND WHY THE FILENAME IS THE MECHANISM.
 * FR-011: closing `POST /workspaces` (code, live the moment the container comes
 * up) while these accounts still have no workspace leaves them with no workspace
 * AND no way to make one — locked out permanently, of a product they signed up
 * for. The birth is code and the backfill is a migration, so `deploy.sh`'s
 * `up -d` then `migrate --force` puts them in the required order by itself. The
 * three migrations of this spec carry ascending timestamps for the same reason:
 * backfill, then move, then delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to rescue: skip the permission sweep entirely. Every
        // `RefreshDatabase` test in the suite replays this migration against an
        // empty database, and 102 writes each time is a cost paid for nothing.
        if (! $this->candidates()->exists()) {
            return;
        }

        /*
        | ⚠️ ONCE, BEFORE THE LOOP — not once per teacher.
        |
        | `SeedDefaultRoles` walks `Permissions::all()` with a `firstOrCreate` for
        | each, and that constant is 102 names, so a single birth is roughly 150
        | queries. Acceptable once in the life of an account; not acceptable
        | multiplied by every teacher on the platform inside one migration. The
        | rows are global (`team_id = null`), so ensuring them here makes every
        | `firstOrCreate` below a hit instead of a write.
        */
        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $action = app(CreateWorkspace::class);

        /*
        | ⚠️ THE MIGRATION OPENS ITS OWN TRANSACTION — Laravel does not.
        | `Schema\Grammars\Grammar::$transactions` is false and only Postgres and
        | SqlServer override it, so on MySQL and SQLite a throw halfway through
        | commits everything before it. `CreateWorkspace` has a transaction of its
        | own; this one makes the whole sweep atomic.
        */
        DB::transaction(function () use ($action): void {
            $this->candidates()->chunkById(100, function ($users) use ($action): void {
                foreach ($users as $user) {
                    $action->handle(
                        new CreateWorkspaceDTO(name: $user->name, type: 'teacher'),
                        $user,
                    );
                }
            });
        });
    }

    /**
     * Teachers who own no workspace and actually applied to teach here.
     *
     * @return Builder<User>
     */
    private function candidates()
    {
        return User::query()
            /*
            | ⚠️ THE SELECT NAMES `first_name` AND `last_name`, and that is not
            | tidiness. `User::name` is an ACCESSOR over those two columns, not a
            | column — a constrained select that omits them returns `'' . ' ' . ''`.
            | Every workspace this migration creates would be born with an empty
            | name, the slug would still generate correctly so nothing would throw,
            | and FR-025 would print a blank label to exactly the people this
            | migration exists to rescue. Six call sites in four modules have
            | already shipped that defect through a constrained eager load.
            */
            ->select(['id', 'first_name', 'last_name', 'platform_role', 'last_workspace_id'])
            ->where('platform_role', PlatformRole::Teacher->value)
            /*
            | «OWNS», not «is a member of» — and spelled on `workspaces.owner_user_id`
            | rather than `users.last_workspace_id`. The latter is written by
            | `AcceptInvitation` and `SwitchWorkspace` too, so for an assistant it
            | names THEIR TEACHER'S workspace: reading it here would silently skip
            | somebody who owns nothing. Spec 024 paid for that fallback once
            | already.
            */
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('workspaces')
                ->whereColumn('workspaces.owner_user_id', 'users.id'))
            /*
            | ⛔ THE THIRD CONDITION, AND WITHOUT IT THIS MIGRATION REOPENS THE
            | DOOR IT WAS WRITTEN TO CLOSE.
            |
            | `RegisterAccount` gives an INVITED ASSISTANT `platform_role = Teacher`
            | by a documented decision — «they are on the teacher's side of every
            | question that reads this column». An assistant owns no workspace and,
            | before accepting, is a member of none either, so the two-condition
            | predicate matches every one of them: each would be handed their own
            | workspace and `tenant-owner`'s 68 permissions, and `CreateWorkspace`
            | overwrites `last_workspace_id` — throwing them out of the workspace
            | of the teacher they work for. SC-006 falls with it.
            |
            | The row that actually separates the two is the application: nothing
            | but `RegisterTeacher` writes `teacher_applications`.
            */
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('teacher_applications')
                ->whereColumn('teacher_applications.user_id', 'users.id'));
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would delete a workspace a teacher has been working in since
     * the deploy — their courses, their sessions, their students' balances. The
     * repository's rule is that a `down()` which cannot restore what was there
     * must say so above itself rather than pretend; this one can restore nothing
     * and must destroy nothing.
     *
     * It is also harmless to re-run `up()`: the «owns no workspace» condition is
     * exactly what makes the second pass find nobody (FR-017).
     */
    public function down(): void {}
};
