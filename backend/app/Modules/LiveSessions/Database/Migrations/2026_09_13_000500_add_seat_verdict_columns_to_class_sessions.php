<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T006 — ثلاثةُ أعمدةٍ تُجمَّدُ عندَ الإغلاق.
     *
     * ⛔ TWO SEAT COUNTS, AND THAT IS THE OWNER'S DECISION OF 2026-09-13,
     * not a redundancy. `attended_seats` is for DISPLAY (FR-015أ);
     * `charged_seats` is WHAT THE TEACHER IS PAID ON (FR-014). They differ
     * because the silent no-show is charged and the teacher takes
     * (FR-008د · row 3) — so a 1-on-1 session whose student never showed
     * reads `attended = 0` and `charged = 1`, and the first would send it to
     * the empty-session branch while the second pays the teacher properly.
     *
     * ⚠️ NULL MEANS «not computed yet», NEVER zero. The fallback branch
     * reads exactly that: a session delivered before this shipment landed
     * keeps the pre-035 rule, which is the rule that was actually in force
     * the moment it was delivered — the same «a verdict belongs to the
     * moment that has passed» principle SC-012 rests on.
     *
     * ⚠️ `verdict_stay_seconds` IS WHAT MAKES SC-012 TRUE. The bar is a
     * percentage an operator edits in `platform_settings`, so storing the
     * number ACTUALLY APPLIED (not the percentage) means moving the setting
     * cannot re-judge the past. Same reason the seat count is frozen today.
     *
     * ⚠️ AND THEY ARE WRITTEN BY A CONDITIONAL UPDATE, never read-then-write:
     * two workers reading attendance a second apart freeze two different
     * numbers, and one of them decides a teacher's pay. They are deliberately
     * absent from `$fillable` and from `UpdateClassSessionRequest` — the
     * `captured_order_id` rule: mass-assignable, they become a second door
     * through which a teacher writes their own wage with a PUT.
     */
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->unsignedInteger('attended_seats')->nullable()->after('billable_seats');
            $table->unsignedInteger('charged_seats')->nullable()->after('attended_seats');
            $table->unsignedInteger('verdict_stay_seconds')->nullable()->after('charged_seats');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['attended_seats', 'charged_seats', 'verdict_stay_seconds']);
        });
    }
};
