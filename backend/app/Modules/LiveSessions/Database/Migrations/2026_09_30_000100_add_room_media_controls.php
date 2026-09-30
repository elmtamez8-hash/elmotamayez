<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the host decided about who may speak and who may share a screen
 * (owner decisions 2026-09-30).
 *
 * ⚠️ STORED, BECAUSE A MUTE THAT LIVES ONLY IN THE PROVIDER LASTS ONE REFRESH —
 * the «إخراج» defect (`removed_at`) reached from a second button. The provider
 * holds a participant's permissions for as long as that connection lives; a
 * student who reloads is issued a NEW ticket, and the ticket is minted from
 * these columns (`RoomMediaRights`), so a muted student comes back muted.
 *
 *  · `attendances.mic_locked_at`           — the host muted THIS student.
 *  · `attendances.mic_allowed_at`          — the host let THIS student speak
 *                                            while the whole room is locked.
 *  · `attendances.screen_share_allowed_at` — the host let THIS student share.
 *  · `class_sessions.mics_locked_at`       — «اكتم الجميع»: every student, and
 *                                            every student who joins later.
 *
 * Timestamps rather than booleans for the reason `removed_at` is one: «when»
 * is the only audit a live lesson has. All nullable, none backfilled — every
 * row before these columns is «nothing was decided», which is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->timestamp('mic_locked_at')->nullable()->after('removed_at');
            $table->timestamp('mic_allowed_at')->nullable()->after('mic_locked_at');
            $table->timestamp('screen_share_allowed_at')->nullable()->after('mic_allowed_at');
        });

        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->timestamp('mics_locked_at')->nullable()->after('room_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['mic_locked_at', 'mic_allowed_at', 'screen_share_allowed_at']);
        });

        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn('mics_locked_at');
        });
    }
};
