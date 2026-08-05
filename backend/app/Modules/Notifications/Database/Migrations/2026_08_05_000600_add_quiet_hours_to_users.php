<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One quiet window per person, not per notification type (FR-032).
 *
 * It sits on `users` rather than on notification_preferences because that is what
 * it describes: when this human is asleep. Storing it per type would let someone
 * be asleep for exam results and awake for attendance alerts at the same hour.
 *
 * Timezone is stored alongside because a window means nothing without one — 22:00
 * is a different instant for a family in Doha and one in Cairo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->time('quiet_hours_start')->nullable()->after('remember_token');
            $table->time('quiet_hours_end')->nullable()->after('quiet_hours_start');
            $table->string('timezone', 64)->nullable()->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'timezone']);
        });
    }
};
