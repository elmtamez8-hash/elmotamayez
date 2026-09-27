<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who called a session off — see `SessionCanceller`.
 *
 * ⚠️ NULLABLE AND NOT BACKFILLED, ON PURPOSE. Every session cancelled before
 * this column existed was cancelled by its teacher, and the one reader
 * (`SyncTeacherCountersJob`) treats null as the teacher, so there is nothing to
 * write and no table walk on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->string('cancelled_by', 16)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn('cancelled_by');
        });
    }
};
