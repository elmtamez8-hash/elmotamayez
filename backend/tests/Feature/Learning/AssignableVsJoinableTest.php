<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Shared\Contracts\CohortDirectory;

/*
| ٠٣٤ · FR-030 — **«صالحةٌ للإسناد» و«صالحةٌ للانضمام» سؤالانِ لا سؤال.**
|
| المجموعةُ **المغلَقةُ غيرُ المكتمِلةِ** وجهةٌ مشروعةٌ للإدارةِ وممنوعةٌ على
| الطالب: `closed` قولٌ عن البابِ لا عن الغرفة، و`CohortMembershipWriter::open()`
| يأخذُ `requireOpen: false` لهذا السببِ بعينِه منذُ ٠٢١ · FR-028ط. فأربعةُ
| قرّاءَ كانوا يسألونَ باسمٍ واحد، والخاسرُ هو المُنتقي: موظَّفٌ أمامَ مجموعةٍ
| مغلقةٍ نصفِ ممتلئةٍ لا يُعرَضُ عليه شيء.
|
| ⚠️ **والحالةُ الأخطرُ هنا هي الثالثة**، وهي التي يسقطُ عليها `T046`: «لا
| مجموعةَ صالحةً» و«لا مجموعاتِ أصلاً» **يُجيبانِ بالقيمةِ نفسِها**، فحارسُ بيعٍ
| بشرطٍ واحدٍ يمنعُ بيعَ كلِّ كورسٍ مسجَّلٍ على المنصّة. وما يُفرِّقُهما هو
| `coursesWithCohorts()` — وهو المقيسُ هنا صراحةً.
|
| ⚠️ **والتحوير**: استبدِلْ `->assignable()` بـ`->joinable()` في
| `coursesWithAssignableCohorts()` ⇒ تسقطُ حالةُ المغلَقةِ وحدَها؛ واحذِفْ
| `->group()` ⇒ تسقطُ حالةُ الغرفةِ الخاصّةِ وحدَها. لا حالةَ تسقطُ بالمصادفة.
*/

/** @return array{0: Course, 1: CohortDirectory} */
function assignableFixture(): array
{
    return [Course::factory()->create(['workspace_id' => 1]), app(CohortDirectory::class)];
}

it('offers a closed group that is not full to administration, and to no student', function (): void {
    [$course, $directory] = assignableFixture();

    Cohort::factory()->closed()->create([
        'workspace_id' => 1,
        'course_id' => $course->getKey(),
    ]);

    expect($directory->assignableCohortsExist((int) $course->getKey()))->toBeTrue()
        ->and($directory->joinableCohortsExist((int) $course->getKey()))->toBeFalse();
});

it('offers a full group to nobody, whichever door is asking', function (): void {
    [$course, $directory] = assignableFixture();

    // ⚠️ `open` DELIBERATELY. The ceiling is the size of the room, and the writer
    // throws `full()` before it looks at the status — so an «assignable» that
    // ignored capacity would build a picker every write refuses.
    Cohort::factory()->full()->create([
        'workspace_id' => 1,
        'course_id' => $course->getKey(),
        'status' => Cohort::OPEN,
    ]);

    expect($directory->assignableCohortsExist((int) $course->getKey()))->toBeFalse()
        ->and($directory->joinableCohortsExist((int) $course->getKey()))->toBeFalse();
});

it('refuses an archived group to administration, which a closed one is not', function (): void {
    [$course, $directory] = assignableFixture();

    Cohort::factory()->archived()->create([
        'workspace_id' => 1,
        'course_id' => $course->getKey(),
    ]);

    expect($directory->assignableCohortsExist((int) $course->getKey()))->toBeFalse();
});

it('never offers somebody private room, however it is left standing', function (): void {
    [$course, $directory] = assignableFixture();

    // Born `closed` with `capacity: 1` — so `isAssignable()` alone would say yes
    // to an empty one, and the officer's picker would put a second student into
    // one named person's private hour.
    Cohort::factory()->closed()->create([
        'workspace_id' => 1,
        'course_id' => $course->getKey(),
        'capacity' => 1,
        'members_count' => 0,
        'individual_for_user_id' => User::factory()->create()->getKey(),
    ]);

    expect($directory->assignableCohortsExist((int) $course->getKey()))->toBeFalse();
});

it('tells «no assignable group» apart from «no groups at all» (T046 falls here)', function (): void {
    [$bare, $directory] = assignableFixture();
    $full = Course::factory()->create(['workspace_id' => 1]);

    Cohort::factory()->full()->create([
        'workspace_id' => 1,
        'course_id' => $full->getKey(),
        'status' => Cohort::OPEN,
    ]);

    $ids = [(int) $bare->getKey(), (int) $full->getKey()];

    // Identical on the assignable question — which is the trap.
    expect($directory->coursesWithAssignableCohorts($ids))->toBe([]);

    // And the second condition is what separates them.
    expect($directory->coursesWithCohorts($ids))->toBe([(int) $full->getKey()]);
});

it('answers a list in one query rather than one question per course', function (): void {
    [$assignable, $directory] = assignableFixture();
    $archivedOnly = Course::factory()->create(['workspace_id' => 1]);

    Cohort::factory()->closed()->create(['workspace_id' => 1, 'course_id' => $assignable->getKey()]);
    Cohort::factory()->archived()->create(['workspace_id' => 1, 'course_id' => $archivedOnly->getKey()]);

    DB::enableQueryLog();

    $answer = $directory->coursesWithAssignableCohorts([
        (int) $assignable->getKey(),
        (int) $archivedOnly->getKey(),
    ]);

    expect($answer)->toBe([(int) $assignable->getKey()])
        ->and(DB::getQueryLog())->toHaveCount(1);
});
