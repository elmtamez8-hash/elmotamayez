<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may act for the PLATFORM, and on whose authority.
 *
 * ⚠️ WHY A TABLE AND NOT A ROW IN `model_has_roles`. spatie runs in team mode
 * here, and that table's primary key is `(team_id, role_id, model_id,
 * model_type)` with `team_id` NOT NULL — so a role belonging to no workspace
 * cannot be attached to anybody. `finance-admin` has been seeded, correct and
 * unassignable since 006 for exactly that reason. The alternatives were a second
 * boolean column beside `is_super_admin` (which the constitution refuses: a
 * column true of one role gets its own table, and the third platform role would
 * want a third column) and altering a primary key on an auth table (a NULL
 * inside a composite PK, whose behaviour differs between MySQL and SQLite —
 * the failing environment being the one nobody runs the suite in).
 *
 * ⚠️ AND IT CARRIES WHY, NOT ONLY WHO. `reason` and `assigned_by` are NOT NULL
 * because this table is the audit trail for the delegation itself: the question
 * an auditor asks about a payment approval is not "was it approved" but "who was
 * allowed to approve it, and who allowed them". A row with neither answers half
 * of that.
 *
 * ⚠️ NO `workspace_id`, BY DESIGN. Platform-owned, like `notifications` and
 * `student_credit_accounts`: one person, one standing, across every workspace on
 * the platform. Adding the column would silently duplicate one officer per
 * teacher — the mirror-image bug `PlatformOwnershipTest` exists to catch in both
 * directions. The guard is therefore written explicitly in the policy, since no
 * global scope touches this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->unsignedBigInteger('assigned_by');
            $table->string('reason');
            $table->timestamps();

            // One standing per person per role, enforced by the database rather
            // than by a check-then-insert: two admins delegating the same officer
            // at the same moment is the ordinary shape of this race.
            $table->unique(['user_id', 'role']);
            $table->index('role');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // Restrict, not cascade: deleting the person who granted an authority
            // must not silently delete the authority — that is a record of a
            // decision, and it outlives the decider's account.
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff');
    }
};
