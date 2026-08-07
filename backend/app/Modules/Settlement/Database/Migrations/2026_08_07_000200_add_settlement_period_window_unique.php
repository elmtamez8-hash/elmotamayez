<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One period per teacher per window, enforced by the database.
 *
 * The settlement job opens a period for any teacher who has unsettled work and
 * no open row. "Look, then insert" is the definition of the race this repo bans
 * by name — two workers both find nothing and both insert, and the teacher now
 * has two open periods over the same days, each claiming half their units.
 *
 * A window is identified by the day it starts on, because that is derived from
 * the previous close and is therefore the same value for both workers. The
 * second insert loses on this index, catches the QueryException and re-reads —
 * the same shape `teaching_units_seat_unique` gives accrual.
 *
 * A separate migration rather than an edit to the create: the first one is
 * already applied, and editing an applied migration means the schema depends on
 * who ran what and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_periods', function (Blueprint $table): void {
            $table->unique(['teacher_profile_id', 'starts_on'], 'settlement_periods_window_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_periods', function (Blueprint $table): void {
            $table->dropUnique('settlement_periods_window_unique');
        });
    }
};
