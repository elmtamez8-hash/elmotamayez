<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| «لا حصّة بلا مجموعة» — والقاعدةُ لها نصفانِ لأنّ السؤالَ ليس واحداً.
|
| ⚠️ حصّةُ المجموعةِ تُرفَضُ عندَ الإنشاءِ إن لم تُسمِّ مجموعتَها. `StoreClassSessionRequest`
| لم يكنْ يحملُ `cohort_uuid` إطلاقاً، فكلُّ حصّةِ مجموعةٍ أُنشِئت من `/manage/sessions`
| وُلِدَت بلا مجموعة — ثمّ أوّلُ مجموعةٍ يُنشِئُها المدرّسُ تُخرِجُها جميعاً من قوائمِ
| الطلابِ دفعةً واحدة، ولوحةُ «حصص محجوبة» ترفضُ إسنادَ ما انعقدَ منها (FR-025و).
|
| ⚠️ والحصّةُ الفرديّةُ المفتوحةُ مُستثناةٌ لأنّ السؤالَ بلا جوابٍ بعد: لا طالبَ فيها،
| فلا مجموعةَ من طالبٍ واحدٍ تخصُّها ولا مَن تُنشَأُ له. تنالُها لحظةَ الحجز.
*/

/** @return array{0: Course, 1: TeacherProfile, 2: mixed, 3: mixed} */
function noGroupFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    app()->forgetInstance(WorkspaceContext::class);

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $test->setCurrentWorkspace($workspace, $owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    return [$course, $teacher, $workspace, $owner];
}

/** @return array<string, mixed> */
function groupSessionPayload(Course $course, int $seats = 6): array
{
    return [
        'course_uuid' => $course->uuid,
        'title' => 'حصة المجموعة',
        'type' => ClassSessionType::Group->value,
        'starts_at' => CarbonImmutable::now()->addDays(3)->startOfHour()->toIso8601String(),
        'duration_minutes' => 60,
        'seats_total' => $seats,
    ];
}

it('refuses a group lesson that names no group', function (): void {
    [$course, , , $owner] = noGroupFixture();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/class-sessions', groupSessionPayload($course))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cohort_uuid');

    expect(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a group that belongs to another course, with the same answer as one that does not exist', function (): void {
    [$course, , $workspace, $owner] = noGroupFixture();

    $other = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $foreign = Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $other->getKey(),
        'name' => 'مجموعة كورس آخر',
    ]);

    Sanctum::actingAs($owner);

    $refusal = $this->postJson('/api/v1/class-sessions', [
        ...groupSessionPayload($course),
        'cohort_uuid' => $foreign->uuid,
    ])->assertStatus(422)->json('errors.cohort_uuid.0');

    $invented = $this->postJson('/api/v1/class-sessions', [
        ...groupSessionPayload($course),
        'cohort_uuid' => (string) Str::uuid(),
    ])->assertStatus(422)->json('errors.cohort_uuid.0');

    // The same sentence either way: a distinct reply would be an oracle for
    // which uuids on the platform are real.
    expect($refusal)->toBe($invented);
});

it('creates a group lesson inside the group it names', function (): void {
    [$course, , $workspace, $owner] = noGroupFixture();

    $cohort = Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'name' => 'مجموعة السبت',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/class-sessions', [
        ...groupSessionPayload($course),
        'cohort_uuid' => $cohort->uuid,
    ])->assertCreated();

    expect((int) ClassSession::query()->withoutWorkspaceScope()->value('cohort_id'))
        ->toBe((int) $cohort->getKey());
});

it('lets an individual slot be created with no group, because it has no student yet', function (): void {
    [$course, , , $owner] = noGroupFixture();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/class-sessions', [
        ...groupSessionPayload($course, seats: 1),
        'type' => ClassSessionType::Individual->value,
    ])->assertCreated();

    expect(ClassSession::query()->withoutWorkspaceScope()->value('cohort_id'))->toBeNull();
});

/*
| ⚠️ النصفُ الثاني: المقعدُ هو ما يُكمِلُ السؤال. وهو آمنٌ فقط لأنّ
| `coursesWithCohorts()` صارَ يُصفّي `->group()` — قبلَها كان كلُّ حجزٍ خاصٍّ
| يجعلُ الكورسَ «ذا مجموعات» فتختفي بقيّةُ حصصِه من جداولِ الطلابِ جميعاً.
*/
it('files a 1:1 slot under the student\'s own one-seat group the moment the seat is taken', function (): void {
    [$course, $teacher, $workspace, $owner] = noGroupFixture();

    $student = $this->addWorkspaceMember($workspace, 'student');
    $this->createEnrollment($workspace, $course, $student);
    $this->setCurrentWorkspace($workspace, $owner);
    fundBooking($workspace, $student, $course);

    $session = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $course->getKey(),
        'type' => ClassSessionType::Individual,
        'seats_total' => 1,
        'seats_taken' => 0,
        'cohort_id' => null,
    ]);

    app(BookSeat::class)->handle($session, $student);

    $cohortId = (int) $session->fresh()->cohort_id;

    expect($cohortId)->toBeGreaterThan(0);

    $cohort = Cohort::query()->withoutWorkspaceScope()->find($cohortId);

    expect((int) $cohort->individual_for_user_id)->toBe((int) $student->getKey())
        // Born closed with one seat: it is one named person's own room, never a
        // run of the course anybody may join.
        ->and($cohort->status)->toBe(Cohort::CLOSED)
        ->and((int) $cohort->capacity)->toBe(1);
});
