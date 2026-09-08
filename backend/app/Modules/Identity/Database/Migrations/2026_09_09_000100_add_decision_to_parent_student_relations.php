<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending guardian link becomes decidable (spec 030).
 *
 * ⚠️ THE ROW IS BORN `pending` AND NOTHING IN `app/` COULD EVER ACTIVATE IT.
 * Measured 2026-09-08: `LinkGuardian` writes `pending` for a child WITH an
 * account and `active` only for a name-only one, `RegisterStudent` writes
 * `pending` from the other side, and there is no third writer of `active`
 * anywhere in the tree. So every guardian-gated feature — the child's balance,
 * the consents, and the guardian purchase shipped in 029 — was dead for any
 * child who holds an account.
 *
 * ⚠️ AND `pending` RUNS IN TWO OPPOSITE DIRECTIONS, which is why the decision
 * cannot be "the student accepts". `RegisterStudent::inviteGuardian` creates the
 * row from the STUDENT's side and waits for the GUARDIAN — its own docblock says
 * a self-registering child must not be able to hand someone authority over their
 * record by typing a phone number. `requested_by_user_id` is what makes one rule
 * serve both: THE PARTY WHO DID NOT ASK IS THE PARTY WHO DECIDES.
 *
 * ⚠️ THIS MIGRATION WRITES NO ROW (FR-011 · SC-007). Backfilling a pending link
 * to active invents a consent nobody gave. Existing rows keep
 * `requested_by_user_id = NULL`, which reads as "not decidable from this path";
 * their exit is the existing revoke plus a fresh request. Measured on the
 * development database the day this was written: `active 16 · revoked 101 ·
 * pending 0`, so there is nothing to strand.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | Closure ONE — the columns, all three together.
        |
        | ⚠️ `constrained()` registers a `foreign` command, which is an ALTER
        | command on SQLite, so this closure is a full table rebuild there
        | (create __temp__, copy, drop, rename). Splitting the columns across two
        | closures would buy two rebuilds instead of one — the opposite of the
        | usual reason for splitting. One closure, one rebuild.
        |
        | `nullOnDelete` rather than the neighbours' `cascadeOnDelete`: today the
        | requester is always one of the two parties and both of those cascade,
        | so the row is gone before this could fire. It is insurance for the day a
        | non-party can request one — the `granted_by` rule from spec 024, where a
        | staff member leaving may not take a family's row with them.
        */
        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->after('student_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('accepted_at')->nullable()->after('status');

            /*
            | The race guard, and it is NOT a display column.
            |
            | FR-012 says a refused link may be requested again, which the old
            | `unique(guardian_user_id, student_user_id)` forbade outright. But
            | replacing that index with an `exists()`-then-`create()` check in the
            | Action is the race this repository bans everywhere else — and the
            | price lands somewhere non-obvious: two active rows for one pair make
            | `EloquentGuardianDirectory::isAuthorised()` (a bare `first()` with no
            | ordering) non-deterministic, and `RevokeRelation` writes ONE row, so
            | THE STUDENT CUTS THE LINK AND ACCESS DOES NOT STOP.
            |
            | So the index stays and widens. `0` while the link is live (pending or
            | active), the row's own id once it is revoked — the `pending_slot`
            | idiom from spec 021 and `captured_order_id` from 007. MySQL has no
            | partial index, which is why the sentinel exists at all.
            |
            | It is deliberately NOT `$fillable`: it is claimed inside the write
            | that ends the row, and mass-assignable it becomes a second way to
            | free the pair from outside that write.
            */
            $table->unsignedBigInteger('live_slot')->default(0)->after('revoked_at');
        });

        /*
        | Closure TWO — the index swap, after the column it needs exists.
        |
        | The new one is created BEFORE the old one is dropped: on MySQL InnoDB
        | refuses to drop the last index supporting a foreign key, and
        | `guardian_user_id` carries one. The momentary overlap costs a "duplicate
        | index" WARNING and never an error, which is the cheap side of the trade.
        |
        | `dropUnique` takes the COLUMN ARRAY, never the name: Laravel's generated
        | name here is `parent_student_relations_guardian_user_id_student_user_id_unique`,
        | exactly 64 characters — MySQL's identifier limit, with zero margin.
        */
        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->unique(['guardian_user_id', 'student_user_id', 'live_slot'], 'psr_live_unique');
        });

        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->dropUnique(['guardian_user_id', 'student_user_id']);
        });
    }

    /**
     * ⚠️ THIS `down()` CANNOT RESTORE THE OLD CONSTRAINT, AND SAYS SO.
     *
     * `up()` is what permits a second row for a pair whose first link was
     * revoked — the whole of FR-012. Re-adding `unique(guardian_user_id,
     * student_user_id)` therefore fails the instant one family has used that
     * feature, and the only way to make it succeed is to delete one of two real
     * links: a decision no migration may make silently. Same shape as
     * `_000600_drop_exam_id_from_questions`, whose `down()` carries the same
     * warning for the same reason.
     *
     * So the rollback returns the columns and the index NAME to where they were
     * and leaves the pair unconstrained. A deployment that truly needs the old
     * constraint back has to decide, by hand, which duplicate links to end.
     */
    public function down(): void
    {
        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->dropUnique('psr_live_unique');
        });

        Schema::table('parent_student_relations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['accepted_at', 'live_slot']);
        });
    }
};
