<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| Spec 023 · US2 — the groups on the course's public page.
|
| ⚠️ THE INDIVIDUAL-GROUP CASE IS THE ONE THAT CAN PASS WITHOUT A GUARD, and it
| is written to make that impossible. A private group is created `closed`, so it
| is absent from a status-filtered payload for a reason that has nothing to do
| with whose it is: a test that leaves it closed cannot tell a correct
| implementation from one filtering on the wrong column. It is OPENED first, and
| then its absence is demanded.
|
| ⚠️ AND «no seats left» IS TESTED AS A MISSING KEY, not as zero.
| `array_key_exists` rather than a value comparison — `$payload['seats_left'] ??
| null` is null both when the key is absent and when it holds null, so the
| obvious spelling passes against a payload that publishes `seats_left: null` for
| every unlimited group and calls it absence.
*/

/** @return array{0: Course, 1: mixed, 2: TeacherProfile} */
function cohortCourse(): array
{
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $teacher->user_id,
        'course_type' => Course::TYPE_GROUP,
    ]));

    /*
     | ⛔ A PRICE, OR THIS WHOLE FILE IS ABOUT A COURSE NOBODY CAN BUY INTO.
     | ٠٣٦ · FR-003 drops a group no live price reaches from the public page
     | entirely, so a fixture with groups and no plan publishes an empty list —
     | which is the correct answer to a question none of these cases is asking.
     | Every case here is about which groups a VISITOR may tell apart.
     */
    groupPriceFor($course);

    return [$course, $workspace, $teacher];
}

/** @param array<string, mixed> $attrs */
function cohortOn(Course $course, string $name, array $attrs = []): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'name' => $name,
        ...$attrs,
    ]);
}

/** @return array<int, array<string, mixed>> */
function publishedCohorts(Course $course): array
{
    return test()->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->json('data.cohorts');
}

it('publishes the three states a visitor must tell apart', function (): void {
    [$course] = cohortCourse();

    cohortOn($course, 'مجموعة السبت', ['capacity' => 8, 'members_count' => 3, 'status' => Cohort::OPEN]);
    cohortOn($course, 'مجموعة الأحد', ['capacity' => 4, 'members_count' => 4, 'status' => Cohort::OPEN]);
    cohortOn($course, 'مجموعة مغلقة', ['capacity' => 10, 'members_count' => 2, 'status' => Cohort::CLOSED]);

    $this->asGuest();

    $states = collect(publishedCohorts($course))->pluck('status', 'name')->all();

    expect($states)->toBe([
        'مجموعة الأحد' => 'full',
        'مجموعة السبت' => 'open',
        'مجموعة مغلقة' => 'closed',
    ]);
});

it('drops an archived group from the public list entirely', function (): void {
    [$course] = cohortCourse();

    cohortOn($course, 'مجموعة قائمة');
    cohortOn($course, 'مجموعة الفصل الماضي', ['status' => Cohort::ARCHIVED, 'archived_at' => now()->subMonth()]);

    $this->asGuest();

    expect(collect(publishedCohorts($course))->pluck('name')->all())->toBe(['مجموعة قائمة']);
});

it('omits the seat count for a group with no declared ceiling', function (): void {
    [$course] = cohortCourse();

    cohortOn($course, 'بلا سقف', ['capacity' => null, 'members_count' => 40]);
    cohortOn($course, 'بسقف', ['capacity' => 6, 'members_count' => 2]);

    $this->asGuest();

    $byName = collect(publishedCohorts($course))->keyBy('name');

    // Absent, not zero and not null: «غير محدود» is not a quantity.
    expect(array_key_exists('seats_left', $byName['بلا سقف']))->toBeFalse()
        ->and($byName['بسقف']['seats_left'])->toBe(4)
        // FR-011: an unlimited group is never full, whatever its counter says.
        ->and($byName['بلا سقف']['status'])->toBe('open');
});

it('publishes not one field about the members', function (): void {
    [$course] = cohortCourse();

    cohortOn($course, 'مجموعة', ['capacity' => 9, 'members_count' => 7]);

    $this->asGuest();

    $cohort = publishedCohorts($course)[0];

    // The raw count is subtracted on the server, so the two halves of the
    // subtraction never both reach a browser (FR-014).
    expect(array_keys($cohort))->not->toContain('members_count')
        ->and(array_keys($cohort))->not->toContain('capacity')
        ->and($cohort['seats_left'])->toBe(2);
});

it('keeps a private group off the public page even when its status is open', function (): void {
    [$course] = cohortCourse();

    cohortOn($course, 'مجموعة عامّة');

    /*
    | ⚠️ THE TRAP, OPENED DELIBERATELY (`quickstart.md` · الفخّ ٣).
    | A private group is born `closed`, so leaving it closed here would test the
    | status filter while claiming to test the ownership filter — and the first
    | day one is opened for any reason at all, its owner's name is on the
    | marketplace. The price of the wrong column is «فلانٌ يأخذُ حصصاً خاصّة»,
    | published to everyone.
    */
    $private = cohortOn($course, 'حصص خاصة', [
        'individual_for_user_id' => User::factory()->create()->getKey(),
        'capacity' => 1,
    ]);
    $private->status = Cohort::OPEN;
    $private->save();

    $this->asGuest();

    $names = collect(publishedCohorts($course))->pluck('name')->all();

    expect($names)->toBe(['مجموعة عامّة'])
        ->and($names)->not->toContain('حصص خاصة');
});

it('answers when the group meets, and an empty list when nothing is scheduled', function (): void {
    [$course, , $teacher] = cohortCourse();

    $scheduled = cohortOn($course, 'مجموعة بمواعيد');
    $bare = cohortOn($course, 'مجموعة بلا مواعيد');

    // Two sessions on the same weekday and hour: the preview is a SUMMARY, so
    // both collapse into one label rather than listing two instants.
    foreach ([1, 8] as $days) {
        /*
        | ⚠️ **بتوقيتِ المنصّةِ ثمّ `->utc()`.** اللافتةُ تُكتَبُ بتوقيتِ الطلاب،
        | والتخزينُ UTC — ووقتٌ هنا بلا منطقةٍ يعني UTC، فتُعلَنُ حصّةُ الرابعةِ
        | «19:00» على صفحةِ الكورسِ العامّة.
        */
        $startsAt = now('Asia/Qatar')->addDays($days)->setTime(16, 0)->utc();

        ClassSession::factory()->create([
            'workspace_id' => $course->workspace_id,
            'teacher_profile_id' => $teacher->getKey(),
            'course_id' => $course->getKey(),
            'cohort_id' => $scheduled->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);
    }

    $this->asGuest();

    $byName = collect(publishedCohorts($course))->keyBy('name');

    expect($byName['مجموعة بمواعيد']['schedule'])->toHaveCount(1)
        ->and($byName['مجموعة بمواعيد']['schedule'][0])->toContain('16:00')
        // An empty list, never a missing key — the screen says «لم تُجدول حصص
        // بعد» rather than rendering nothing, which reads as a broken section.
        ->and($byName['مجموعة بلا مواعيد']['schedule'])->toBe([]);
});

/*
| The same slots as INSTANTS, for the visitor's own clock (owner decision
| 2026-09-26). «السبت ٥م» in Doha is 16:00 in Cairo in winter and 17:00 in
| summer, and only a date says which — so the payload carries the NEXT meeting in
| each slot, in the same order as the labels, and the browser draws it.
*/
it('sends each slot as the next meeting instant, beside the platform-zone label', function (): void {
    [$course, , $teacher] = cohortCourse();

    $scheduled = cohortOn($course, 'مجموعة بمواعيد');
    $bare = cohortOn($course, 'مجموعة بلا مواعيد');
    $first = now('Asia/Qatar')->addDays(1)->setTime(17, 0)->utc();

    foreach ([0, 7] as $days) {
        $startsAt = $first->copy()->addDays($days);

        ClassSession::factory()->create([
            'workspace_id' => $course->workspace_id,
            'teacher_profile_id' => $teacher->getKey(),
            'course_id' => $course->getKey(),
            'cohort_id' => $scheduled->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);
    }

    $this->asGuest();

    $byName = collect(publishedCohorts($course))->keyBy('name');

    expect($byName['مجموعة بمواعيد']['schedule_slots'])->toHaveCount(1)
        // The NEXT meeting, not the later one.
        ->and(CarbonImmutable::parse($byName['مجموعة بمواعيد']['schedule_slots'][0])->equalTo($first))->toBeTrue()
        ->and($byName['مجموعة بمواعيد']['schedule'][0])->toContain('17:00')
        ->and($byName['مجموعة بلا مواعيد']['schedule_slots'])->toBe([]);
});

it('returns an empty list for a course with no groups at all', function (): void {
    [$course] = cohortCourse();

    $this->asGuest();

    expect(publishedCohorts($course))->toBe([]);
});
