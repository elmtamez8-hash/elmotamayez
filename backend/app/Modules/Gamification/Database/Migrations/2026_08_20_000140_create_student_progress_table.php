<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per student, for the whole product — PLATFORM-owned (layer أ).
 *
 * ⚠️ NO workspace_id, AND IT MUST NEVER GAIN ONE. Adding BelongsToWorkspace here
 * would produce a separate xp, level and streak PER TEACHER the student studies
 * with: they would watch their own level drop by switching context, and a
 * hundred-day streak would become four short ones. It is the mirror-image bug
 * PlatformOwnershipTest exists to catch in both directions, and the reason
 * coin_balances DOES carry the trait while this table does not — the shop is the
 * teacher's, the person is not.
 *
 * Every write to this table is CONDITIONAL and monotonic in one direction
 * (research §R9); read the Action, not this file, for why each WHERE is there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progress', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->unique();

            /*
            | Experience never converts to anything and only accumulates, so an
            | unsigned 32-bit column is four billion points of headroom — decades
            | at any value the catalogue could hold. It goes DOWN only through a
            | penalty, and the Action clamps the deduction to the current balance
            | before the statement runs, so the column can never be asked to hold
            | a negative (NFR-013 · the MySQL-only ERROR 1690 that SQLite cannot
            | reproduce).
            */
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(1);

            $table->unsignedSmallInteger('current_streak')->default(0);
            $table->unsignedSmallInteger('best_streak')->default(0);

            /** The Doha day of the last activity, `Y-m-d`. */
            $table->string('last_active_day', 10)->nullable();

            /*
            | The last Doha day the streak was EVALUATED. Stamped inside the very
            | statement that consumes a shield — precedent: notified_dormant_at in
            | spec 006. Without it an evaluation that runs twice burns two shields
            | for one missed day, and CLAUDE.md already records 72 accumulated
            | passes after a single worker restart.
            */
            $table->string('streak_evaluated_day', 10)->nullable();

            /*
            | Streak shields, bought from the shop.
            |
            | A COLUMN AND NOT A TABLE, deliberately: a shield is fungible, so a
            | count answers every question a table of identical rows would.
            */
            $table->unsignedSmallInteger('shield_count')->default(0);

            /*
            | The highest level the student has already been congratulated for.
            | Moved by `WHERE notified_level < :n`, so a level that dips between
            | two requests cannot fire LevelReachedUp — and a duplicate
            | congratulation — a second time.
            */
            $table->unsignedSmallInteger('notified_level')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progress');
    }
};
