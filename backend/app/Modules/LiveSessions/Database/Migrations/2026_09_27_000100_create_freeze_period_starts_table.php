<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per freeze ever DECLARED — the count the monthly ceiling reads.
 *
 * ⚠️ NOT `freeze_periods`, because lifting a freeze DELETES its row (every
 * reader of that table — booking, scheduling, the absentee sweep, the
 * subscription extension — must stop seeing it the moment it is lifted). A
 * ceiling counted there gave its slot back on every lift, so freeze → lift →
 * freeze walked straight past it (owner decision 2026-09-27: a lifted freeze
 * still counts). This ledger is written once, at declaration, and never
 * deleted by a lift.
 *
 * Backfilled from the periods that exist now; periods lifted before this
 * migration left no row to count, and are not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freeze_period_starts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            // null = the whole workspace; a value = a freeze on one student.
            // The same two scopes `freeze_periods` has.
            $table->unsignedBigInteger('student_user_id')->nullable();
            $table->date('starts_on');
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'student_user_id', 'starts_on'], 'freeze_period_starts_scope_month_index');
        });

        DB::table('freeze_periods')
            ->orderBy('id')
            ->select(['workspace_id', 'student_user_id', 'starts_on', 'created_at'])
            ->chunk(500, function ($rows): void {
                DB::table('freeze_period_starts')->insert($rows->map(static fn ($row): array => [
                    'workspace_id' => $row->workspace_id,
                    'student_user_id' => $row->student_user_id,
                    'starts_on' => substr((string) $row->starts_on, 0, 10),
                    'created_at' => $row->created_at,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('freeze_period_starts');
    }
};
