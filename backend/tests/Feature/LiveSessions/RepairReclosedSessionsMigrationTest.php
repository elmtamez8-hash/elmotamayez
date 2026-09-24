<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| The data half of PR #177: two production sessions were abandoned (the teacher
| never opened the room) and then re-closed an hour later by the sweep, which
| invented a register, a zero verdict and a failed recording for them.
|
| Three shapes, because the selector is the whole risk: the damaged one; the
| seeded demo one (`completed`, no room, but `recording_status` NULL) which must
| not move; and a real lesson whose recording genuinely failed (the room DID
| open) which must not move either.
*/

beforeEach(function (): void {
    Queue::fake([SyncTeacherCountersJob::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

function runReclosedRepairMigration(): void
{
    (require base_path(
        'app/Modules/LiveSessions/Database/Migrations/2026_09_24_000500_repair_sessions_reclosed_after_abandonment.php'
    ))->up();
}

/** @param array<string, mixed> $overrides */
function reclosedFixture(array $overrides = []): ClassSession
{
    $test = test();
    $closedAt = CarbonImmutable::now()->subDays(2);

    $session = ClassSession::factory()->create(array_merge([
        'teacher_profile_id' => $test->teacher->getKey(),
        'title' => 'حصة لم تُعقد',
        'starts_at' => $closedAt->subHours(3),
        'ends_at' => $closedAt->subHours(2),
        'duration_minutes' => 60,
        'status' => ClassSessionStatus::Completed,
        'room_opened_at' => null,
        'room_closed_at' => $closedAt,
        'recording_status' => 'failed',
        'recording_attempts' => 5,
        'recording_attempted_at' => $closedAt->addHours(2),
        'attended_seats' => 0,
        'charged_seats' => 0,
        'verdict_stay_seconds' => 2700,
    ], $overrides));

    SessionBooking::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $test->student->getKey(),
    ]);

    Attendance::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $test->student->getKey(),
        'confirmed_at' => $closedAt,
    ]);

    foreach ([
        [$test->owner, 'session_recording_failed', null],
        [$test->student, 'session_recording_unavailable', null],
        [$test->student, 'session_report', $test->student],
    ] as [$recipient, $type, $subject]) {
        Notification::query()->create([
            'recipient_user_id' => $recipient->getKey(),
            'workspace_id' => $test->workspace->getKey(),
            'type' => $type,
            'subject_user_id' => $subject?->getKey(),
            'payload' => ['title' => $session->title],
            'title' => 'x',
            'body' => 'x',
            'action_url' => '/schedule',
        ]);
    }

    return $session;
}

function reclosedNotificationTypesFor(User $user): array
{
    return Notification::query()->where('recipient_user_id', $user->getKey())->pluck('type')->sort()->values()->all();
}

it('returns a re-closed session to what AbandonClassSession leaves', function (): void {
    $session = reclosedFixture();

    runReclosedRepairMigration();

    $row = DB::table('class_sessions')->where('id', $session->getKey())->first();

    expect($row->status)->toBe('interrupted')
        ->and($row->interruption_note)->toBe('teacher_no_show')
        ->and($row->room_closed_at)->toBeNull()
        ->and($row->delivered_at)->toBeNull()
        ->and($row->attended_seats)->toBeNull()
        ->and($row->charged_seats)->toBeNull()
        ->and($row->verdict_stay_seconds)->toBeNull()
        ->and($row->recording_status)->toBeNull()
        ->and((int) $row->recording_attempts)->toBe(0)
        ->and($row->recording_attempted_at)->toBeNull();

    // The invented absence is gone; the seat itself is untouched.
    expect(DB::table('attendances')->where('class_session_id', $session->getKey())->count())->toBe(0)
        ->and(DB::table('session_bookings')->where('class_session_id', $session->getKey())->count())->toBe(1);

    // Both recording-failure rows and the «غائب» report are deleted.
    expect(reclosedNotificationTypesFor($this->owner))->toBe([])
        ->and(reclosedNotificationTypesFor($this->student))->toBe([]);

    Queue::assertPushed(SyncTeacherCountersJob::class, 1);
    expect(DB::table('teaching_units')->count())->toBe(0);
});

it('deletes the guardian\'s copy of the absence report and its delivery row', function (): void {
    /*
    | A guardian's copy is addressed to the guardian and names the child as its
    | SUBJECT — matching on the recipient alone would leave exactly the message
    | the owner asked to remove: a parent told their child skipped a lesson that
    | never happened.
    */
    $session = reclosedFixture();
    $guardian = User::factory()->create();

    $report = Notification::query()->create([
        'recipient_user_id' => $guardian->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'type' => 'session_report',
        'subject_user_id' => $this->student->getKey(),
        'payload' => ['title' => $session->title],
        'title' => 'x',
        'body' => 'x',
        'action_url' => '/dashboard',
    ]);

    DB::table('notification_deliveries')->insert([
        'uuid' => (string) Str::uuid(),
        'notification_id' => $report->getKey(),
        'channel' => 'whatsapp',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runReclosedRepairMigration();

    expect(reclosedNotificationTypesFor($guardian))->toBe([])
        ->and(DB::table('notification_deliveries')->where('notification_id', $report->getKey())->count())->toBe(0);
});

it('keeps the absence report of a session it does not repair', function (): void {
    // A real lesson whose recording failed: the room opened, the register is
    // true, and its report is the family's to keep.
    reclosedFixture(['room_opened_at' => CarbonImmutable::now()->subDays(2)->subHours(3)]);

    runReclosedRepairMigration();

    expect(reclosedNotificationTypesFor($this->student))
        ->toBe(['session_recording_unavailable', 'session_report']);
});

it('leaves a seeded completed session with no recording state alone', function (): void {
    $seeded = reclosedFixture([
        'recording_status' => null,
        'recording_attempts' => 0,
        'recording_attempted_at' => null,
    ]);

    runReclosedRepairMigration();

    expect(DB::table('class_sessions')->where('id', $seeded->getKey())->value('status'))->toBe('completed')
        ->and(DB::table('attendances')->where('class_session_id', $seeded->getKey())->count())->toBe(1)
        ->and(reclosedNotificationTypesFor($this->owner))->toBe(['session_recording_failed']);

    Queue::assertNotPushed(SyncTeacherCountersJob::class);
});

it('leaves a real lesson whose recording genuinely failed alone', function (): void {
    $real = reclosedFixture(['room_opened_at' => CarbonImmutable::now()->subDays(2)->subHours(3)]);

    runReclosedRepairMigration();

    expect(DB::table('class_sessions')->where('id', $real->getKey())->value('status'))->toBe('completed')
        ->and(DB::table('class_sessions')->where('id', $real->getKey())->value('recording_status'))->toBe('failed')
        ->and(DB::table('attendances')->where('class_session_id', $real->getKey())->count())->toBe(1)
        ->and(reclosedNotificationTypesFor($this->owner))->toBe(['session_recording_failed']);
});

it('does not delete a recording notification about a different session', function (): void {
    $session = reclosedFixture();

    Notification::query()->create([
        'recipient_user_id' => $this->owner->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'type' => 'session_recording_failed',
        'payload' => ['title' => 'حصة أخرى'],
        'title' => 'x',
        'body' => 'x',
        'action_url' => '/manage/sessions/other',
    ]);

    runReclosedRepairMigration();

    expect(DB::table('class_sessions')->where('id', $session->getKey())->value('status'))->toBe('interrupted')
        ->and(reclosedNotificationTypesFor($this->owner))->toBe(['session_recording_failed']);
});
