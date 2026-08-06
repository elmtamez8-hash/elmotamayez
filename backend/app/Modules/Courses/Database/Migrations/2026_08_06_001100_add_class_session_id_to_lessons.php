<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lesson published from a live session's recording knows which session it came
 * from.
 *
 * This one column is what makes the third entitlement route possible: a
 * recording is watchable by whoever booked a seat, not by everyone enrolled in
 * the course (FR-030). Without it, publishing the recording as an ordinary
 * lesson would quietly widen access to every enrolled student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('class_session_id')->nullable()->after('chapter_id');
            $table->index('class_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['class_session_id']);
            $table->dropColumn('class_session_id');
        });
    }
};
