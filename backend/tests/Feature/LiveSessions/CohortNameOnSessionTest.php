<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| اسمُ المجموعةِ على صفِّ الحصّة.
|
| ⚠️ ولا علاقةَ `cohort()` على `ClassSession` ولا يجوزُ أن تكون: المجموعةُ نموذجُ
| «التعلّم»، و`CohortSessionVisibility` يقولُ بالحرفِ إنّ هذه الوحدةَ لا تستوردُه.
| فالاسمُ يُختَمُ دفعةً واحدةً عبرَ العقد.
|
| ⚠️ والميزانيّةُ هي الاختبار. مَورِدٌ يُنفَّذُ مرّةً لكلِّ صفّ، فاسمٌ يُسأَلُ داخلَه
| هو N+1 بحكمِ البناء — خمسونَ استعلاماً على تقويمِ شهرٍ، على الشاشةِ التي يفتحُها
| المدرّسُ أوّلَ كلِّ صباح. والحالةُ الثانيةُ هنا تسقطُ إن نُقِلَ السؤالُ إلى المَورِد.
*/

/** @return array<string, mixed> */
function cohortNameFixture(int $sessions = 1): array
{
    /** @var TestCase $test */
    $test = test();

    app()->forgetInstance(WorkspaceContext::class);

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $test->setCurrentWorkspace($workspace, $owner);

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'course_type' => Course::TYPE_GROUP,
    ]);

    $cohortId = groupCohortIdFor((int) $course->getKey(), (int) $workspace->getKey());

    $at = CarbonImmutable::now()->utc()->addWeek()->startOfWeek()->addDays(2)->setTime(13, 0);

    for ($i = 0; $i < $sessions; $i++) {
        app(ScheduleClassSession::class)->handle(new ScheduleSessionData(
            teacherProfileId: (int) $profile->getKey(),
            title: 'درس '.$i,
            type: ClassSessionType::Group,
            startsAt: $at->addDays($i),
            durationMinutes: 60,
            seatsTotal: 6,
            courseId: (int) $course->getKey(),
            cohortId: $cohortId,
        ), $owner);
    }

    return ['workspace' => $workspace, 'owner' => $owner, 'course' => $course, 'cohortId' => $cohortId];
}

it('names the group on every session row', function (): void {
    $fixture = cohortNameFixture();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/v1/class-sessions')
        ->assertOk()
        // The name the teacher gave the group — `groupCohortIdFor()` creates it.
        ->assertJsonPath('data.0.cohort_name', 'مجموعة الاختبار');
});

it('asks once for a whole page, not once per row', function (): void {
    $fixture = cohortNameFixture(sessions: 5);

    Sanctum::actingAs($fixture['owner']);

    // Warmed twice: the first request pays for `platform_settings` rows this path
    // caches, and one warm-up is not steady — the presence budget flaked by one
    // for exactly that reason and the fix was a second warm-up, never a bigger
    // ceiling.
    $this->getJson('/api/v1/class-sessions');
    $this->getJson('/api/v1/class-sessions');

    /*
    | `DB::listen` rather than `countingQueries()`: that helper answers a TOTAL,
    | and a total cannot tell a cohort read from the five eager loads beside it.
    | What is being measured here is one particular table.
    */
    $cohortReads = 0;

    DB::listen(function ($query) use (&$cohortReads): void {
        if (str_contains($query->sql, 'from "cohorts"')) {
            $cohortReads++;
        }
    });

    $this->getJson('/api/v1/class-sessions')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    /*
    | ⚠️ **واحدٌ لا خمسة**، والرقمُ هو كلُّ الاختبار. سؤالُ الاسمِ داخلَ المَورِدِ
    | يمرُّ من الحالةِ الأولى فوقَه سالماً — الاسمُ صحيحٌ في الحالتَين — ولا يسقطُ
    | إلّا هنا. وهي عائلةُ `ClassSessionResource` نفسِها التي سألت كلَّ حصّةٍ
    | منشورةٍ أينَ ذهبَ تسجيلُها، صفّاً صفّاً.
    */
    expect($cohortReads)->toBe(1);
});

it('says «no group» rather than nothing, for a session that has none', function (): void {
    $fixture = cohortNameFixture();

    // A session with no group at all — every session in this product predates
    // groups and carries `cohort_id = null`.
    ClassSession::query()
        ->withoutWorkspaceScope()
        ->update(['cohort_id' => null]);

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/v1/class-sessions')
        ->assertOk()
        /*
        | ⚠️ المفتاحُ حاضرٌ وقيمتُه `null`، لا مفتاحٌ غائب. كلُّ صفٍّ مرَّ من الختمِ
        | له جواب: `null` تعني «بلا مجموعة» ولا تعني «لم يسألْ أحد» — والفرقُ هو
        | ما يمنعُ قارئاً لاحقاً من قراءةِ نسيانٍ على أنّه حقيقة.
        */
        ->assertJsonPath('data.0.cohort_name', null);
});
