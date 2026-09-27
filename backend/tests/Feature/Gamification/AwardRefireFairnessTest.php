<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\FinalizeAttempt;
use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\EvaluateBadges;
use App\Modules\Gamification\Actions\ReinstateAward;
use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;

/**
 * An award event that fires AGAIN must neither pay twice, nor spend a slot, nor
 * leave a corrected cause stuck in the wrong state.
 *
 * ⚠️ EVERY ASSERTION IS ON THE AGGREGATE — the student's experience and purse —
 * never on a row count. Counting rows passes against a design that writes the
 * right number of entries and moves nothing (the `AwardReversalTest` lesson).
 *
 * Three defects, one per section:
 *
 *  1. a duplicate award (a re-fired `SubmissionGraded`, a revised paper) spent
 *     one of today's capped slots while paying nothing, so the student's
 *     genuine next award that day was refused;
 *  2. present → absent → present ended with the attendance points gone for ever,
 *     because the re-award collided with the original's idempotency key;
 *  3. a pass revised into a fail kept its `exam_passed` points.
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

function refireXp(User $student): int
{
    return (int) (StudentProgress::query()->where('user_id', $student->getKey())->value('xp') ?? 0);
}

function refirePurse(User $student, int $workspaceId): int
{
    return (int) (CoinBalance::query()
        ->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->where('workspace_id', $workspaceId)
        ->value('coins') ?? 0);
}

/** A sitting whose verdict is decided by the exam's threshold alone: no items ⇒ 0%. */
function refireSit(Course $course, User $student): Attempt
{
    $exam = Exam::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'passing_score' => 0,
    ]);

    $attempt = Attempt::query()->create([
        'workspace_id' => $course->workspace_id,
        'exam_id' => $exam->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADING,
        'is_practice' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    return app(FinalizeAttempt::class)->handle($attempt);
}

/**
 * What `ReviseGrade` does after a mark changes: re-run the finalisation. The
 * threshold stands in for the revised marks — raising it above the score is a
 * revision that turns the pass into a fail.
 */
function refireRevise(Attempt $attempt, bool $passes): Attempt
{
    Exam::query()->withoutWorkspaceScope()->whereKey($attempt->exam_id)
        ->update(['passing_score' => $passes ? 0 : 60]);

    return app(FinalizeAttempt::class)->handle($attempt->refresh());
}

function refireSession(object $test): ClassSession
{
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'user_id' => $test->owner->getKey(),
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
    ]);

    // The student holds a seat: a register row with no booking behind it is
    // staff in the room, never a student (`Attendance::scopeExcludingHost()`).
    SessionBooking::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $test->student->getKey(),
    ]);

    return $session;
}

function refireMark(Attendance $row, AttendanceStatus $status): void
{
    $row->forceFill(['status' => $status])->save();

    event(new AttendanceOverridden($row));
}

// ─── 1 · a duplicate spends no slot ─────────────────────────────────────────

it('does not spend a daily slot on a re-graded submission, so the next genuine one still pays', function (): void {
    $ws = (int) $this->workspace->getKey();
    $graded = Submission::factory()->create(['workspace_id' => $ws, 'student_user_id' => $this->student->getKey()]);

    // `homework_submitted` is capped at three a day. The first grading pays;
    // the next three are re-gradings of the same hand-in.
    foreach (range(1, 4) as $ignored) {
        event(new SubmissionGraded($graded));
    }

    $before = refireXp($this->student);

    $fresh = Submission::factory()->create(['workspace_id' => $ws, 'student_user_id' => $this->student->getKey()]);
    event(new SubmissionGraded($fresh));

    expect(refireXp($this->student))->toBe($before + 15);
});

it('gives the slot back on a duplicate even when called directly', function (): void {
    $ws = (int) $this->workspace->getKey();

    $request = fn (int $id): AwardRequest => new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'exam_passed',
        sourceType: 'attempt',
        sourceId: $id,
        workspaceId: $ws,
    );

    // Capped at two. One real award, then two redeliveries of it.
    app(AwardPoints::class)->handle($request(1));
    app(AwardPoints::class)->handle($request(1));
    app(AwardPoints::class)->handle($request(1));

    $before = refireXp($this->student);

    expect(app(AwardPoints::class)->handle($request(2)))->not->toBeNull()
        ->and(refireXp($this->student))->toBe($before + 50);
});

it('lets a genuine pass earn after the same passed paper was revised twice in a day', function (): void {
    $attempt = refireSit($this->course, $this->student);

    refireRevise($attempt, passes: true);
    refireRevise($attempt, passes: true);

    $before = refireXp($this->student);

    refireSit($this->course, $this->student);

    expect(refireXp($this->student))->toBe($before + 50);
});

// ─── 2 · attendance round trips ─────────────────────────────────────────────

it('ends present → absent → present with the attendance points paid exactly once', function (): void {
    $ws = (int) $this->workspace->getKey();
    $session = refireSession($this);
    $row = attendanceRow($this->workspace, $session, $this->student, AttendanceStatus::Present);

    $xp = refireXp($this->student);
    $coins = refirePurse($this->student, $ws);

    event(new AttendanceConfirmed($session));

    expect(refireXp($this->student))->toBe($xp + 10)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 5);

    refireMark($row, AttendanceStatus::Absent);

    expect(refireXp($this->student))->toBe($xp)
        ->and(refirePurse($this->student, $ws))->toBe($coins);

    refireMark($row, AttendanceStatus::Present);

    expect(refireXp($this->student))->toBe($xp + 10)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 5);

    // And again — the second round trip negates the reinstatement, not the
    // original, which the unique key would have refused.
    refireMark($row, AttendanceStatus::Absent);

    expect(refireXp($this->student))->toBe($xp);

    refireMark($row, AttendanceStatus::Late);

    expect(refireXp($this->student))->toBe($xp + 10)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 5);
});

it('changes nothing when the same mark is overridden twice', function (): void {
    $ws = (int) $this->workspace->getKey();
    $session = refireSession($this);
    $row = attendanceRow($this->workspace, $session, $this->student, AttendanceStatus::Present);

    $xp = refireXp($this->student);
    $coins = refirePurse($this->student, $ws);

    event(new AttendanceConfirmed($session));

    refireMark($row, AttendanceStatus::Absent);
    refireMark($row, AttendanceStatus::Absent);

    expect(refireXp($this->student))->toBe($xp);

    refireMark($row, AttendanceStatus::Present);
    refireMark($row, AttendanceStatus::Present);
    event(new AttendanceConfirmed($session));

    expect(refireXp($this->student))->toBe($xp + 10)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 5);
});

it('gives back exactly what the reversal could take, never the nominal amount', function (): void {
    $ws = (int) $this->workspace->getKey();
    $session = refireSession($this);
    $row = attendanceRow($this->workspace, $session, $this->student, AttendanceStatus::Present);

    event(new AttendanceConfirmed($session));

    // The student spent three of the five coins before the correction arrived.
    CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $this->student->getKey())
        ->where('workspace_id', $ws)
        ->update(['coins' => 2]);

    refireMark($row, AttendanceStatus::Absent);

    expect(refirePurse($this->student, $ws))->toBe(0);

    refireMark($row, AttendanceStatus::Present);

    // Two came back, not five: the cause sums to one award of five, and the
    // three that were spent stay spent.
    expect(refirePurse($this->student, $ws))->toBe(2)
        ->and((int) AwardEntry::query()
            ->where('source_type', 'class_session')
            ->where('source_id', $session->getKey())
            ->sum('coins'))->toBe(5);
});

it('counts a reinstated cause once towards a counting badge', function (): void {
    $ws = (int) $this->workspace->getKey();

    Badge::query()->where('key', 'regular_attender')->update(['rule_value' => 2]);

    $request = fn (int $id): AwardRequest => new AwardRequest(
        studentUserId: (int) $this->student->getKey(),
        actionKey: 'session_attended',
        sourceType: 'class_session',
        sourceId: $id,
        workspaceId: $ws,
    );

    $first = app(AwardPoints::class)->handle($request(1));
    app(AwardPoints::class)->handle($request(2));

    app(ReverseAward::class)->handle($first);
    app(ReinstateAward::class)->handle($first);

    BadgeAward::query()->delete();
    app(EvaluateBadges::class)->handle($this->student);

    // Two sessions, both currently paid. "Originals minus every other row"
    // counted the reinstatement as a second reversal and made this zero.
    expect(BadgeAward::query()->where('badge_key', 'regular_attender')->exists())->toBeTrue();
});

// ─── 3 · a revised verdict moves the exam points ────────────────────────────

it('takes the exam points back when a revision turns the pass into a fail, and returns them when it turns back', function (): void {
    $ws = (int) $this->workspace->getKey();

    $xp = refireXp($this->student);
    $coins = refirePurse($this->student, $ws);

    $attempt = refireSit($this->course, $this->student);

    expect(refireXp($this->student))->toBe($xp + 50)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 20);

    refireRevise($attempt, passes: false);

    expect(refireXp($this->student))->toBe($xp)
        ->and(refirePurse($this->student, $ws))->toBe($coins);

    // A second failing revision takes nothing more.
    refireRevise($attempt, passes: false);

    expect(refireXp($this->student))->toBe($xp);

    refireRevise($attempt, passes: true);

    expect(refireXp($this->student))->toBe($xp + 50)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 20);

    // And a second passing revision pays nothing more.
    refireRevise($attempt, passes: true);

    expect(refireXp($this->student))->toBe($xp + 50)
        ->and(refirePurse($this->student, $ws))->toBe($coins + 20);
});
