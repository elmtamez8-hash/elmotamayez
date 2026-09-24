<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The academic warning's claim (`academic_warning`, 2026-09-24).
|
| Stamped on the attempt whose failure COMPLETED the streak, by a conditional
| `UPDATE … WHERE academic_warning_at IS NULL` before the dispatch. It is what
| stops a second warning for the same streak when `ExamFailed` fires again for
| the same attempt — `ReviseGrade` re-finalizes a paper after a grade is
| corrected, and a queued listener may be redelivered.
|
| No backfill: nothing re-reads an attempt finalized before this column
| existed, so no old streak is warned about late.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('academic_warning_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn('academic_warning_at');
        });
    }
};
