<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T007 — العذرُ الماليُّ حقيقةٌ عن الحجز، تُكتَبُ قبلَ القفل.
     *
     * ⚠️ WITHOUT THIS COLUMN THE FOURTH ROW OF FR-008د CANNOT BE
     * IMPLEMENTED AT ALL. `AttendanceStatus::Excused` has exactly one writer
     * in `app/` — `OverrideAttendance` — and its window opens at
     * `ends_at + attendanceEditWindowHours` (`OverrideAttendance.php:49`),
     * which is AFTER `CloseClassSession` has frozen the verdict and charged.
     * So the teacher's educational excuse arrives too late to exempt anybody.
     *
     * ⚠️ AND «EXCUSED» NOW MEANS TWO DIFFERENT THINGS ON TWO DIFFERENT
     * COLUMNS, deliberately: the FINANCIAL excuse is this column on the
     * booking (no charge, content stays locked), and the EDUCATIONAL one is
     * `attendances.status = excused` (counts as attended for the next
     * booking's eligibility). The first developer to unify them in good
     * faith breaks one of the two doors — which is why
     * `ExcusedTwoMeaningsTest` exists.
     *
     * Not indexed: read through a booking already resolved by
     * `(class_session_id, student_user_id)`.
     */
    public function up(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->timestamp('excused_at')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('excused_by_user_id')->nullable()->after('excused_at');
        });
    }

    public function down(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->dropColumn(['excused_at', 'excused_by_user_id']);
        });
    }
};
