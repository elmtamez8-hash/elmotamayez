<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The award ledger — the truth of this whole phase.
 *
 * Everything else in spec 009 is derived from these rows: `student_progress` is
 * a running aggregate kept so no request has to sum the ledger, and
 * `leaderboard_entries` is an indexed table rebuilt from here by one command
 * whenever it is doubted (SC-011). There is no number in this system that cannot
 * be proved by reading this table line by line.
 *
 * BRIDGE layer: it carries a workspace_id for CONTEXT and points at the platform
 * user. Append-only, enforced on the model (AwardEntry::booted throws on
 * updating/deleting), the same shape as Settlement's LedgerEntry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('award_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('student_user_id');

            /*
            | Text, not a foreign key. An action can be retired from the catalogue
            | and every entry it ever wrote must stay readable — a row that says
            | "you earned this for something that no longer exists" is still an
            | honest answer; a broken join is not.
            */
            $table->string('action_key', 64);

            /*
            | ⚠️ WHAT WAS ACTUALLY APPLIED, frozen at the moment of the award, and
            | CLAMPED to what could actually be deducted (research §R10).
            |
            | Writing the nominal −50 when only 20 could come off leaves the sum of
            | the entries at −30 while the aggregate sits at 0, permanently: FR-005
            | ("the balance equals the sum of its entries") would contradict FR-009
            | ("no balance may go negative") by construction, and the nightly
            | reconciliation would report a drift the design itself requires.
            |
            | Signed, because a reversal and a penalty are both negative rows.
            */
            $table->integer('xp')->default(0);
            $table->integer('coins')->default(0);

            /*
            | The teacher's context. Nullable: a platform-wide action (a focus
            | session) belongs to no workspace.
            |
            | ⚠️ PASSED EXPLICITLY ON INSERT, never left to BelongsToWorkspace's
            | auto-fill. Awards are written with insertOrIgnore — the model is
            | never booted — and the resolved context is null for a student and
            | for every queued job anyway.
            */
            $table->unsignedBigInteger('workspace_id')->nullable();

            /*
            | Where it happened. Every leaderboard scope is derived from these:
            | `courses.subject_id` and `courses.grade_level` have existed since
            | spec 006 and are both indexed.
            */
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();

            /*
            | The student's level band AT THE MOMENT OF THE AWARD.
            |
            | ⚠️ WITHOUT THIS COLUMN SC-011 IS NOT ACHIEVABLE. Leaderboard rows are
            | deleted and rebuilt from this ledger; if the band were not frozen
            | here, the rebuild would re-slice by the student's CURRENT level and
            | produce a different ranking from the one that was shown. Same
            | principle as freezing xp and coins above.
            */
            $table->unsignedSmallInteger('level_band')->default(0);

            /*
            | What caused it. Both NOT NULL — they sit inside the unique key below,
            | and a nullable column inside a unique index does not bite.
            */
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');

            /*
            | ⚠️ ZERO IS A SENTINEL, AND `NULL` HERE WOULD BE THE ATTRACTIVE WRONG
            | ANSWER — precedent: concept_stats.lesson_id in spec 008.
            |
            | A reversal must not collide with the entry it reverses, so this
            | column sits inside the idempotency key. Made nullable, `NULL != NULL`
            | means the key stops biting for EVERY ordinary award and the same
            | event replayed twice would pay twice. Zero compares, so it bites.
            */
            $table->unsignedBigInteger('reversal_of_id')->default(0);

            $table->timestamps();

            /*
            | The idempotency guard. Spec FR-008 and FR-010 in one index: the same
            | event delivered twice writes one row, and a reversal is a DIFFERENT
            | row because reversal_of_id differs.
            */
            $table->unique(
                ['student_user_id', 'action_key', 'source_type', 'source_id', 'reversal_of_id'],
                'award_entries_idempotency_unique',
            );

            /*
            | Equality, equality, range — which is what the daily cap actually
            | asks. An index on (student, created_at) alone leaves the optimiser
            | preferring the unique key above (two leading equalities, no time
            | bound) and reading everything the student ever earned from that
            | action. It is also what badge evaluation asks ("answered 100
            | questions in total").
            */
            $table->index(['student_user_id', 'action_key', 'created_at'], 'award_entries_cap_index');

            /*
            | The platform-wide rollup: no workspace, no course, just a window of
            | time. Without an index that LEADS with created_at it scans the whole
            | table every hour.
            */
            $table->index(['created_at', 'student_user_id'], 'award_entries_rollup_index');

            $table->index(['workspace_id', 'created_at']);
            $table->index(['course_id', 'created_at']);

            /*
            | ⚠️ AND NO SINGLE-COLUMN INDEX ON ANY FOREIGN KEY. Every composite
            | above already leads with its key column, so a separate one buys
            | nothing and costs a write on the one table every single award
            | touches — which is the table SC-016 is measured against.
            */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('award_entries');
    }
};
