<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| صفحةُ المجموعة: بياناتُها، ومواعيدُها، وتوليدُ مواعيدَ لها من جدولِ التوفّرِ.
|
| ⚠️ الكورسُ يسافرُ مع المجموعة. الصفحةُ تُفتَحُ بمعرّفِ المجموعةِ وحدَه، وكلُّ ما
| يُفعَلُ فيها يحتاجُ الكورس — وجلبُ كلِّ الكورساتِ للعثورِ على صاحبِ مجموعةٍ واحدةٍ
| قراءةُ قائمةٍ لسؤالٍ يعرفُ صفٌّ واحدٌ جوابَه.
|
| ⚠️ والمولِّدُ يكتبُ حصصاً بتواريخَ وساعات، لا مراجعَ إلى شقوقِ التوفّر:
| `SetAvailability` يحذفُ الصفوفَ ويُعيدُ إنشاءَها عندَ كلِّ حفظ، فمعرّفاتُ الشقوقِ
| تتغيّرُ كلّما أعادَ المدرّسُ كتابةَ أسبوعِه.
*/

/** @return array{0: Cohort, 1: Course, 2: TeacherProfile, 3: mixed, 4: mixed} */
function cohortPageFixture(): array
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
        'title' => 'الفيزياء',
    ]);

    $cohort = Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'name' => 'مجموعة السبت',
        'capacity' => 12,
    ]);

    return [$cohort, $course, $teacher, $workspace, $owner];
}

it('answers one group with the course it is a run of', function (): void {
    [$cohort, $course, , , $owner] = cohortPageFixture();

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/manage/cohorts/{$cohort->uuid}")
        ->assertOk()
        ->assertJsonPath('uuid', (string) $cohort->uuid)
        ->assertJsonPath('capacity', 12)
        ->assertJsonPath('course.uuid', (string) $course->uuid)
        ->assertJsonPath('course.title', 'الفيزياء');
});

it('filters the calendar to one group, and to nothing at all for a uuid it cannot resolve', function (): void {
    [$cohort, $course, $teacher, $workspace, $owner] = cohortPageFixture();

    $make = fn (?int $cohortId, string $title): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $course->getKey(),
        'cohort_id' => $cohortId,
        'title' => $title,
    ]);

    $make((int) $cohort->getKey(), 'حصة المجموعة');
    $make(null, 'حصة بلا مجموعة');

    Sanctum::actingAs($owner);

    $titles = collect(
        $this->getJson("/api/v1/class-sessions?cohort={$cohort->uuid}")->assertOk()->json('data'),
    )->pluck('title')->all();

    expect($titles)->toBe(['حصة المجموعة']);

    /*
    | ⚠️ فارغةٌ لا كاملة. فلترٌ لا يُحَلُّ ويُتجاهَلُ بصمتٍ يعرضُ على المدرّسِ كلَّ
    | حصصِ المساحةِ تحتَ اسمِ مجموعة — وهي القاعدةُ نفسُها التي يحملُها فلترا
    | المدرّسِ والكورسِ فوقَه.
    */
    $none = $this->getJson('/api/v1/class-sessions?cohort='.Str::uuid()->toString())
        ->assertOk()->json('data');

    expect($none)->toBe([]);
});

it('generates the group\'s dates from the weekly schedule, into the group', function (): void {
    [$cohort, $course, $teacher, , $owner] = cohortPageFixture();

    $from = CarbonImmutable::now()->addDay()->startOfDay();

    AvailabilitySlot::query()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'day_of_week' => (int) $from->format('w'),
        'start_time' => '16:00',
        'end_time' => '17:00',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/class-sessions/generate', [
        'course_uuid' => $course->uuid,
        'cohort_uuid' => $cohort->uuid,
        'from' => $from->toDateString(),
        'to' => $from->addDays(2)->toDateString(),
        'type' => ClassSessionType::Group->value,
        'seats_total' => 12,
    ])->assertCreated();

    $session = ClassSession::query()->withoutWorkspaceScope()->first();

    expect($session)->not->toBeNull()
        ->and((int) $session->cohort_id)->toBe((int) $cohort->getKey())
        ->and($session->status)->toBe(ClassSessionStatus::Scheduled);
});

it('refuses a group that is not this course\'s', function (): void {
    [, $course, , $workspace, $owner] = cohortPageFixture();

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

    $this->postJson('/api/v1/class-sessions/generate', [
        'course_uuid' => $course->uuid,
        'cohort_uuid' => $foreign->uuid,
        'from' => CarbonImmutable::now()->addDay()->toDateString(),
        'to' => CarbonImmutable::now()->addDays(3)->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('cohort_uuid');
});
