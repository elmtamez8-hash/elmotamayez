<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Puts back the sessions the hourly sweep re-closed after abandoning them.
 *
 * PR #177 fixed the cause: `AbandonClassSession` wrote `interrupted` (the teacher
 * never opened the room), `CloseStaleSessionsJob` still selected `interrupted`,
 * and an hour later handed the same row to `CloseClassSession`. That Action then
 * stamped `room_closed_at` on a room that never existed, wrote an Absent row for
 * every seat holder, froze a zero verdict, set `completed`, and fired
 * `SessionCompleted` — whose ingest listener asked the provider about a room with
 * no egress five times, wrote `recording_status = failed`, and told the teacher
 * «تعذّر نشر تسجيل الحصة» and every seat holder «التسجيل غير متاح».
 *
 * ⚠️ SELECTED BY SHAPE, NEVER BY ID. `completed` + `room_opened_at IS NULL` +
 * `recording_status = 'failed'` is the fingerprint: a lesson that really ran has
 * `room_opened_at` (only `OpenBroadcastRoom` writes `live`, and it writes both in
 * one statement), and the seeded demo sessions — also `completed` with no room —
 * carry `recording_status = NULL` because nothing ever ingested them. Two rows on
 * production matched on 2026-09-24.
 *
 * What each row is returned to is exactly what `AbandonClassSession` leaves:
 * `interrupted` with `teacher_no_show`, no room timestamps, no verdict, no
 * recording state. The credits were already released by the abandonment itself
 * (and again by the close, idempotently), so nothing here touches money.
 *
 * ⚠️ SETTLEMENT IS READ, NEVER WRITTEN. A session that was never delivered has no
 * teaching unit (`SessionDelivered` never fired). A row that somehow has one is
 * skipped and logged rather than repaired — whatever wrote it is a different
 * defect, and reversing a wage belongs to the ledger's own Actions.
 *
 * ⚠️ AND THE ABSENCE REPORTS GO TOO (owner decision 2026-09-24). The close also
 * dispatched `SendSessionReportsJob`, which told each seat holder and their
 * guardians «غائب» about a lesson nobody held. A guardian's copy carries the
 * child as its SUBJECT rather than as its recipient, which is why the match asks
 * both columns.
 *
 * `attendance_alert` is listed for completeness and cannot match here:
 * `SendAbsenceAlerts` returns on a session with no `delivered_at`, and every row
 * this migration selects has none. It is matched on its own payload key
 * (`session_title`), so if the shape ever existed it would go the same way.
 */
return new class extends Migration
{
    /** The notifications the wrong close produced, and the payload key naming the session. */
    private const FALSE_NOTIFICATIONS = [
        'session_recording_failed' => 'title',
        'session_recording_unavailable' => 'title',
        'session_report' => 'title',
        'attendance_alert' => 'session_title',
    ];

    public function up(): void
    {
        $sessions = DB::table('class_sessions')
            ->where('status', 'completed')
            ->whereNull('room_opened_at')
            ->where('recording_status', 'failed')
            ->whereNull('delivered_at')
            ->get(['id', 'uuid', 'workspace_id', 'teacher_profile_id', 'title', 'starts_at', 'room_closed_at', 'media_asset_id']);

        $teachers = [];

        foreach ($sessions as $session) {
            $id = (int) $session->id;

            if ($session->media_asset_id !== null) {
                Log::warning('live_sessions.repair_reclosed.skipped', ['session_id' => $id, 'reason' => 'media_asset']);

                continue;
            }

            if (DB::table('teaching_units')->where('class_session_id', $id)->exists()) {
                Log::warning('live_sessions.repair_reclosed.skipped', ['session_id' => $id, 'reason' => 'teaching_unit']);

                continue;
            }

            // Everything the wrong close wrote after this moment is its own.
            $since = (string) ($session->room_closed_at ?? $session->starts_at);

            $deleted = DB::transaction(function () use ($session, $id, $since): int {
                DB::table('class_sessions')->where('id', $id)->where('status', 'completed')->update([
                    'status' => 'interrupted',
                    'interruption_note' => 'teacher_no_show',
                    'room_closed_at' => null,
                    'delivered_at' => null,
                    'attended_seats' => null,
                    'charged_seats' => null,
                    'verdict_stay_seconds' => null,
                    'recording_status' => null,
                    'recording_attempts' => 0,
                    'recording_attempted_at' => null,
                    'updated_at' => now(),
                ]);

                // The room never opened, so nobody could have joined: every row
                // here is an Absent mark the close invented. Guarded anyway —
                // a row with a join or a teacher's override is not the close's.
                DB::table('attendances')
                    ->where('class_session_id', $id)
                    ->whereNull('first_joined_at')
                    ->whereNull('overridden_at')
                    ->where('source', 'automatic')
                    ->delete();

                $ids = $this->matchingNotifications($session, $since);

                foreach (array_chunk($ids, 500) as $chunk) {
                    // The foreign key cascades on MySQL; deleted explicitly so the
                    // result does not depend on the engine enforcing it.
                    DB::table('notification_deliveries')->whereIn('notification_id', $chunk)->delete();
                    DB::table('notifications')->whereIn('id', $chunk)->delete();
                }

                return count($ids);
            });

            Log::info('live_sessions.repair_reclosed.repaired', [
                'session_id' => $id,
                'notifications_deleted' => $deleted,
            ]);

            $teachers[(int) $session->teacher_profile_id] = true;
        }

        // Counted the way the code counts, never recomputed here. Queued on
        // production; the job enters the workspace through `forWorkspace()`.
        foreach (array_keys($teachers) as $teacherProfileId) {
            SyncTeacherCountersJob::dispatch($teacherProfileId);
        }
    }

    /**
     * The false notifications a close of this session produced.
     *
     * None of them carries a source, so the session is recognised by what they
     * do carry: its workspace, a recipient who is its teacher or one of its seat
     * holders (or a guardian whose subject is one), the moment of the close, and
     * the session's title under the type's own payload key — compared in PHP,
     * because a JSON path over Arabic text is two dialects and one of them
     * escapes.
     *
     * @return list<int>
     */
    private function matchingNotifications(stdClass $session, string $since): array
    {
        $people = DB::table('session_bookings')
            ->where('class_session_id', $session->id)
            ->pluck('student_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $teacherUserId = DB::table('teacher_profiles')->where('id', $session->teacher_profile_id)->value('user_id');

        if ($teacherUserId !== null) {
            $people[] = (int) $teacherUserId;
        }

        if ($people === []) {
            return [];
        }

        return array_values(DB::table('notifications')
            ->whereIn('type', array_keys(self::FALSE_NOTIFICATIONS))
            ->where('workspace_id', $session->workspace_id)
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($people): void {
                $query->whereIn('recipient_user_id', $people)
                    ->orWhereIn('subject_user_id', $people);
            })
            ->get(['id', 'type', 'payload'])
            ->filter(static function (object $row) use ($session): bool {
                $payload = json_decode((string) $row->payload, true);
                $key = self::FALSE_NOTIFICATIONS[(string) $row->type] ?? null;

                return is_array($payload) && $key !== null && ($payload[$key] ?? null) === $session->title;
            })
            ->map(static fn (object $row): int => (int) $row->id)
            ->all());
    }

    /*
    | Nothing to restore. The rows deleted were false statements — an absence
    | from a lesson that was never held, a recording that could never exist —
    | and re-creating them would re-send nobody anything while making the
    | register say it again. Rolling back past this point means a backup.
    */
    public function down(): void {}
};
