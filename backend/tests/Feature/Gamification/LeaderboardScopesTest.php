<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use Laravel\Sanctum\Sanctum;

/**
 * The picker, and the one property that makes it worth having (FR-020).
 *
 * ⚠️ FIVE OF THE SIX SCOPES SHIPPED REACHABLE ONLY BY TYPING A URL. They worked,
 * they were tested, `docs/README.md` listed them as delivered — and the screen
 * offered `platform` and nothing else.
 *
 * ⚠️ TWO WORKSPACES, ALWAYS. With one, a "cross-workspace" board is
 * indistinguishable from a per-workspace one and the assertions pass green over a
 * design that never crossed anything.
 */
beforeEach(function (): void {
    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$this->workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $this->subject = Subject::query()->firstOrCreate(['slug' => 'math'], ['name' => 'الرياضيات']);
    GradeLevel::query()->firstOrCreate(['slug' => 'secondary'], ['name' => 'الثانوية']);

    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspaceA->getKey(),
        'title' => 'رياضيات الثانوية',
        'subject_id' => $this->subject->getKey(),
        'grade_level' => 'secondary',
    ]);

    // The board the student must NOT be offered: another teacher's course, in a
    // workspace they have never enrolled in.
    $this->foreign = Course::factory()->create([
        'workspace_id' => $this->workspaceB->getKey(),
        'title' => 'كورس مدرّسٍ آخر',
        'subject_id' => $this->subject->getKey(),
        'grade_level' => 'secondary',
    ]);
});

function enrolForScopes(User $student, Course $course, string $status = 'active'): Enrollment
{
    return Enrollment::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => $status,
    ]);
}

/*
 * ⚠️ NAMED `enrolForScopes`, NOT `enrol` — Pest helpers declared in a test FILE
 * land in the GLOBAL function namespace, and `Notifications/PlatformOwnershipTest`
 * already owns that name. The collision is a fatal error in whichever file the
 * runner loads second, which is a suite that fails a long way from the change.
 *
 * @return list<array{scope: string, label: string, kind: string}>
 */
function scopesFor(User $student): array
{
    Sanctum::actingAs($student);
    test()->asGuest();

    /** @var list<array{scope: string, label: string, kind: string}> $data */
    $data = test()->getJson('/api/v1/gamification/leaderboard/scopes')->assertOk()->json('data');

    return $data;
}

it('offers a board of every kind the student has a place in', function (): void {
    enrolForScopes($this->student, $this->course);

    $kinds = array_column(scopesFor($this->student), 'kind');

    expect($kinds)->toContain('platform')
        ->and($kinds)->toContain('grade')
        ->and($kinds)->toContain('subject')
        ->and($kinds)->toContain('teacher')
        ->and($kinds)->toContain('course')
        /*
        | ⚠️ `lesson` IS ABSENT ON PURPOSE, and this asserts it rather than leaving
        | it to be noticed. A per-lesson ranking is a question asked beside the
        | lesson; a global picker listing every lesson of every course is a list
        | nobody reads. The scope stays live for a caller that has the context.
        */
        ->and($kinds)->not->toContain('lesson');
});

/*
 * THE ONE THAT MATTERS: nothing offered is refused when it is opened.
 *
 * ⚠️ THE OBVIOUS SOURCE FOR "WHICH TEACHERS" IS THE STUDENT'S PURSES, AND IT IS
 * WRONG IN BOTH DIRECTIONS. `ReadLeaderboard` authorises a teacher board on an
 * ACTIVE ENROLMENT, while a coin purse outlives the enrolment (a departed
 * teacher's coins still show, deliberately) and lags it (a fresh enrolment has
 * earned nothing yet). A picker built on purses offers boards the API answers 403
 * and hides boards it allows — one question spelled two ways, which is the defect
 * `BookingEligibility` already cost this repository once.
 *
 * This walks every option through the real endpoint. It is what fails if the two
 * spellings ever drift apart again.
 */
it('offers nothing the board itself refuses', function (): void {
    enrolForScopes($this->student, $this->course);

    $scopes = scopesFor($this->student);

    expect($scopes)->not->toBeEmpty();

    foreach ($scopes as $option) {
        Sanctum::actingAs($this->student);
        test()->asGuest();

        test()->getJson('/api/v1/gamification/leaderboard?scope='.urlencode($option['scope']))
            ->assertOk();
    }
});

it('never names a course or a teacher the student has not enrolled with', function (): void {
    enrolForScopes($this->student, $this->course);

    $text = json_encode(scopesFor($this->student), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    // The Arabic needle has to survive encoding — `getContent()` escapes non-ASCII,
    // so an assertion written against the raw body would pass whatever it held.
    expect($text)->toContain('رياضيات الثانوية')
        ->and($text)->not->toContain('كورس مدرّسٍ آخر')
        ->and($text)->not->toContain((string) $this->foreign->uuid);
});

it('drops a course the moment the enrolment stops being active', function (): void {
    $enrollment = enrolForScopes($this->student, $this->course);

    expect(array_column(scopesFor($this->student), 'kind'))->toContain('course');

    $enrollment->update(['status' => 'cancelled']);

    // Down to the platform board alone — which is still offered, because a focus
    // session earns points outside every workspace and ranks there.
    $kinds = array_column(scopesFor($this->student), 'kind');

    expect($kinds)->toBe(['platform']);
});

/*
 * A teacher gets an empty list, not a refusal.
 *
 * Every scope is closed to them — the cross-workspace three by role, the other
 * three for want of an enrolment they cannot hold in their own workspace — so an
 * empty list is the true answer rather than a softened one.
 */
it('answers a teacher with nothing at all', function (): void {
    expect(scopesFor($this->ownerA))->toBe([]);
});

/*
 * And the identifier on the wire is a uuid or a slug, never a sequential id.
 *
 * Two reasons and both matter: `HasUuid` is the repository's rule for every route
 * and payload, and `subject:1`, `subject:2`, … is an enumeration of every course
 * and teacher on the platform with an activity signal attached.
 */
it('names every scope by uuid or slug', function (): void {
    enrolForScopes($this->student, $this->course);

    foreach (scopesFor($this->student) as $option) {
        if ($option['kind'] === 'platform') {
            continue;
        }

        $identifier = explode(':', $option['scope'], 2)[1];

        expect($identifier)->not->toBeNumeric();
    }
});
