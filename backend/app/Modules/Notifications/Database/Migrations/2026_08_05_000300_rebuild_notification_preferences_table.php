<?php

declare(strict_types=1);

use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rebuilds preferences as one row per (user, type) mapping onto a channel list.
 *
 * The old shape was two booleans on one row — `weekly_reports` and
 * `session_alerts`. That cannot express FR-027 ("a type mapped to a list of
 * channels") no matter how many booleans get added, so the table is replaced
 * rather than extended.
 *
 * Carrying the old choices over (FR-031):
 *   session_alerts = false  →  attendance_alert and appointment_reminder, both
 *                              with an empty channel list. That is what the user
 *                              was asking for under the old wording.
 *   weekly_reports = false  →  nothing. No weekly report exists yet, so there is
 *                              no type to map it onto and no choice to lose.
 *
 * Users who never changed anything get no rows at all. Absence means "defaults
 * apply" (FR-028), which is both correct and one less row per user.
 *
 * Ownership layer: PLATFORM-OWNED. The guard is user_id — a student has one set
 * of preferences across every teacher they study with, not one per academy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $optedOut = Schema::hasTable('notification_preferences')
            ? DB::table('notification_preferences')->where('session_alerts', false)->pluck('user_id')->all()
            : [];

        Schema::dropIfExists('notification_preferences');

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('channels');

            // Null means no digesting: deliver each one as it happens (FR-034).
            $table->unsignedSmallInteger('digest_window_minutes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        if ($optedOut === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($optedOut as $userId) {
            foreach ([NotificationType::AttendanceAlert, NotificationType::AppointmentReminder] as $type) {
                $rows[] = [
                    'uuid' => (string) Str::orderedUuid(),
                    'user_id' => $userId,
                    'type' => $type->value,
                    'channels' => json_encode([]),
                    'digest_window_minutes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('notification_preferences')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('weekly_reports')->default(true);
            $table->boolean('session_alerts')->default(true);
            $table->timestamps();
        });
    }
};
