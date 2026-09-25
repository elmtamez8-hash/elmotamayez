<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

/*
| FR-021د · SC-023 — «شاهد التسجيل لاحقاً» is written by WATCHING, through the
| real doors, and by nothing else.
|
| `RecordRecordingWatched` existed since spec 005 and was reached by one test
| only, so the register's line could never appear for anybody in production: a
| column with a reader (`AttendanceResource` → `AttendanceSheet`) and no writer.
| These cases drive the two routes a student's player actually calls —
| `POST /lessons/{lesson}/playback` then `POST /playback/{grant}/renew` — and
| read the register row afterwards.
|
| The threshold is the SERVER clock since the grant was minted, half the
| asset's duration by default. The asset here is 600 s long, so the line is 300 s
| — and every renewal stays inside the 300 s grant TTL, or the grant is dead
| before the case reaches the moment it is about.
*/

uses(MediaFixtures::class);

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->lesson = $this->lessonWithVideo($this->workspace);
    $this->lesson->mediaAsset->forceFill(['duration_seconds' => 600])->save();

    $this->session = app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): ClassSession {
        $profile = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

        return ClassSession::factory()->create([
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $this->lesson->course_id,
            // Delivered AND judged: `attended_seats` null is the «open to
            // everyone» deploy-window arm, which would let the stranger case in.
            'delivered_at' => now()->subDay(),
            'attended_seats' => 1,
        ]);
    });

    $this->lesson->forceFill(['class_session_id' => $this->session->getKey()])->save();

    // A seat holder who was charged for the hour but never came — the student
    // this line exists for. A closure, not a file-level function, because it
    // calls the fixture trait's protected helpers.
    $this->absentee = function (?int $stampWorkspaceId = null): User {
        $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

        if ($stampWorkspaceId !== null) {
            // ⚠️ forceFill: `last_workspace_id` is guarded, and `create([...])`
            // would drop it in silence and rebuild the null-context student.
            $student->forceFill(['last_workspace_id' => $stampWorkspaceId])->save();
        }

        SessionBooking::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $this->session->getKey(),
            'student_user_id' => $student->getKey(),
        ]);

        attendanceRow($this->workspace, $this->session, $student, AttendanceStatus::Absent, charged: true);

        $this->enrolledViewer($this->workspace, $this->lesson, $student);

        return $student;
    };
});

function watchedWiringRow(ClassSession $session, User $user): Attendance
{
    return Attendance::withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $user->getKey())
        ->firstOrFail();
}

it('records the watch once, from the renewal loop, and moves no status', function (): void {
    $student = ($this->absentee)();

    $grant = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk()->json('grant');

    $this->travel(4)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 240])->assertOk();

    expect(watchedWiringRow($this->session, $student)->recording_watched_at)->toBeNull();

    $this->travel(2)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 360])->assertOk();

    $row = watchedWiringRow($this->session, $student);
    $stampedAt = $row->recording_watched_at?->toIso8601String();

    expect($stampedAt)->not->toBeNull()
        // SC-023: the fact sits beside the status and never moves it.
        ->and($row->status)->toBe(AttendanceStatus::Absent);

    // Later renewals of the same grant, and a second viewing on a fresh grant,
    // must not move the first watch. A count alone would pass against a build
    // that re-stamps every minute; the VALUE is what is asserted.
    $this->travel(3)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 540])->assertOk();

    $second = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk()->json('grant');
    $this->travel(4)->minutes();
    $this->postJson("/api/v1/playback/{$second}/renew")->assertOk();
    $this->travel(2)->minutes();
    $this->postJson("/api/v1/playback/{$second}/renew")->assertOk();

    expect(watchedWiringRow($this->session, $student)->recording_watched_at?->toIso8601String())->toBe($stampedAt)
        ->and(Attendance::withoutWorkspaceScope()->whereNotNull('recording_watched_at')->count())->toBe(1);
});

it('reads the server clock, not the position the player reports', function (): void {
    $student = ($this->absentee)();

    $grant = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk()->json('grant');

    // A forged «I am at the end» a minute in, renewed as fast as a script likes.
    $this->travel(1)->minutes();
    foreach (range(1, 5) as $ignored) {
        $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 600])->assertOk();
    }

    expect(watchedWiringRow($this->session, $student)->recording_watched_at)->toBeNull();
});

it('records it for a student stamped with another teacher workspace', function (): void {
    [$elsewhere] = $this->createWorkspaceWithOwner();
    $student = ($this->absentee)((int) $elsewhere->getKey());

    $grant = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk()->json('grant');

    $this->travel(4)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew")->assertOk();
    $this->travel(2)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew")->assertOk();

    expect(watchedWiringRow($this->session, $student)->recording_watched_at)->not->toBeNull();
});

it('records nothing for an enrolled student who never received the hour', function (): void {
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->enrolledViewer($this->workspace, $this->lesson, $stranger);

    $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertForbidden();

    expect(Attendance::withoutWorkspaceScope()->whereNotNull('recording_watched_at')->count())->toBe(0)
        ->and(Attendance::withoutWorkspaceScope()->where('student_user_id', $stranger->getKey())->exists())->toBeFalse();
});

it('does not mark the teacher as having watched their own lesson', function (): void {
    attendanceRow($this->workspace, $this->session, $this->owner, AttendanceStatus::Present);

    Sanctum::actingAs($this->owner);
    $this->asGuest();
    $auth = $this->sessionFor($this->owner);
    $auth->forceFill(['token_id' => $this->owner->currentAccessToken()->getKey()])->save();

    $grant = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk()->json('grant');

    $this->travel(4)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew")->assertOk();
    $this->travel(2)->minutes();
    $this->postJson("/api/v1/playback/{$grant}/renew")->assertOk();

    expect(watchedWiringRow($this->session, $this->owner)->recording_watched_at)->toBeNull();
});
