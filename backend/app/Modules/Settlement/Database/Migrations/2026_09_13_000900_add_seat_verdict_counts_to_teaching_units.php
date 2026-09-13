<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ٠٣٥ · T071 · FR-014 — the two counts, frozen onto the unit beside `frozen_seats`.
 *
 * Three numbers about one hour, and they are three different questions: how many
 * seats were held when the cancellation window shut, how many people were in the
 * room, and how many of those seats were charged. The third is the wage base and
 * the first is no longer it.
 *
 * They are copied onto the unit rather than read through `class_session_id`
 * because a unit is a FROZEN record of a past moment — the same reason
 * `frozen_seats` is a column and not a join. A statement that re-derived them
 * would show a teacher a different answer every time somebody edited the
 * register afterwards.
 *
 * ⚠️ NULLABLE, AND NULL MEANS «NOT JUDGED». A session delivered before this
 * shipment has no verdict to copy, and zero there would read as «nobody attended
 * and nobody was charged» on every hour the platform has already paid for. It is
 * the same spelling `AccrueTeachingUnits` reads for `$chargedSeats === null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teaching_units', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attended_seats')->nullable()->after('frozen_seats');
            $table->unsignedSmallInteger('charged_seats')->nullable()->after('attended_seats');
        });
    }

    public function down(): void
    {
        Schema::table('teaching_units', function (Blueprint $table): void {
            $table->dropColumn(['attended_seats', 'charged_seats']);
        });
    }
};
