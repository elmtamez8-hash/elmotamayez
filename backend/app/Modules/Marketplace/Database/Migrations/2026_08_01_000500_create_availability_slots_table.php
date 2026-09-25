<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday
            // ⚠️ Superseded 2026-09-25: wall-clock time + `timezone` (see
            // 2026_09_25_000200). Originally: stored in UTC; the visitor's timezone is applied in the UI so the same
            // row reads correctly for a viewer outside Qatar (FR-029).
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['teacher_profile_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_slots');
    }
};
