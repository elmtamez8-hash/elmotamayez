<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **التبويبُ يتبعُ الجدولَ، والشارةُ تتبعُ الإعلان — وهما سؤالان.**
|
| صفحةُ الكورسِ عندَ الطالبِ كانت تشتقُّ «هل لهذا الكورسِ حصص؟» من
| `course_type !== 'recorded'`، وذلكَ العمودُ عاشَ **بلا كاتبٍ في الشجرةِ
| كلِّها** من ٢٠٢٦-٠٨-٠١ إلى ٢٠٢٦-٠٩-١٥: قيمةٌ افتراضيّةٌ كتبَها الجدولُ لا
| إنسان، تُقرَأُ كأنّها قرار. فكورسٌ على الإنتاجِ بثماني حصصٍ حيّةٍ جايّةٍ
| ومجموعةٍ مفتوحةٍ لم يعرضْ تبويبَ «الحصص» ولا عدّادَ الحصّةِ القادمةِ لأربعةِ
| طلبةٍ مسجَّلين — ولا شيءَ في أيِّ مكانٍ قالَ لماذا.
|
| ⚠️ **وصارَ للعمودِ كاتبٌ وما زالَ لا يكفي، لأنّ اتّجاهَي العطلِ غيرُ
| متكافئَين**: مدرّسٌ يُخطئُ التصنيفَ يُخفي الجدولَ عن طلبتِه **في صمت** — لا
| خطأَ ولا شارةَ ولا أثر — بينما الخطأُ نفسُه في السوقِ شارةٌ ظاهرةٌ يراها
| الناسُ فيُبلِّغون. فسؤالُ التبويبِ يُجابُ من الجدول، وسؤالُ السوقِ من
| الإعلان.
|
| **كيفَ يمسك**: احذفْ `withExists('classSessions')` من `CourseController` ⇒
| يسقطُ شقُّ «المفتاحُ حاضرٌ وصادق» — وهو الشقُّ الذي يقيسُ الحضورَ لا القيمةَ
| وحدَها، لأنّ سمةً غائبةً تُقرَأُ `false` في صمتٍ وتُسكِتُ التحذيرَ عن الكورسِ
| الذي كُتِبَ من أجلِه بالضبط.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);
});

/** كورسٌ يقولُ «مسجّل» وله حصّةٌ حيّةٌ في الجدول — الحالةُ التي شُحِنَت معطوبة. */
function mislabelledCourse(): Course
{
    $test = test();

    $course = Course::factory()->published()->create([
        'workspace_id' => $test->workspace->getKey(),
        'created_by' => $test->owner->getKey(),
        'course_type' => Course::TYPE_RECORDED,
    ]);

    ClassSession::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'teacher_profile_id' => $test->teacher->getKey(),
        'course_id' => $course->getKey(),
        'starts_at' => CarbonImmutable::now()->addDay(),
        'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    return $course;
}

it('tells the teacher the truth beside the label', function (): void {
    $course = mislabelledCourse();

    Sanctum::actingAs($this->owner);

    $payload = $this->getJson("/api/v1/courses/{$course->uuid}")->assertOk()->json();

    // ⚠️ الحضورُ أوّلاً: مفتاحٌ غائبٌ يُقرَأُ في الواجهةِ `false` في صمت، وهو
    // بعينِه ما يُسكِتُ التحذيرَ عن الكورسِ الذي كُتِبَ من أجلِه.
    expect(array_keys($payload))->toContain('has_sessions')
        ->and($payload['has_sessions'])->toBeTrue()
        // والإعلانُ يُرسَلُ كما هو، بلا تصحيحٍ من الخادم: الشاشةُ تحتاجُ
        // الاثنَينِ لتقولَ إنّهما اختلفا.
        ->and($payload['course_type'])->toBe(Course::TYPE_RECORDED);
});

it('says false on a course with nothing scheduled, whatever its label', function (): void {
    $course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        // العكسُ كذلك: «جماعي» بلا حصصٍ بعدُ هو أوّلُ يومٍ في حياةِ كلِّ كورسٍ
        // جماعيّ، والجوابُ الصادقُ عنه «لا حصصَ بعد».
        'course_type' => Course::TYPE_GROUP,
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('has_sessions', false);
});

/*
| ⚠️ **والفهرسُ كذلك، وبتكلفةٍ ثابتة.** `withExists` استعلامٌ فرعيٌّ واحدٌ لكلِّ
| الصفحة؛ قراءةٌ لكلِّ صفٍّ هي الـN+1 التي تحرسُ منها `SC-011`.
*/
it('carries the key on every row of the index, at a flat cost', function (): void {
    foreach (range(1, 3) as $ignored) {
        mislabelledCourse();
    }

    Sanctum::actingAs($this->owner);

    [$count, $payload] = countingQueries(fn () => $this->getJson('/api/v1/courses')->assertOk()->json());

    expect($payload['data'])->toHaveCount(3);

    foreach ($payload['data'] as $row) {
        expect(array_keys($row))->toContain('has_sessions')
            ->and($row['has_sessions'])->toBeTrue();
    }

    // ميزانيّةٌ فضفاضةٌ عمداً: المقصودُ ألّا تنموَ مع الصفوف، لا أن تُثبَّتَ على
    // رقم. ثلاثةُ كورساتٍ بقراءةٍ لكلِّ صفٍّ تتجاوزُها.
    expect($count)->toBeLessThanOrEqual(15);
});

/*
| ⛔ **وحمولةُ الطالبِ هي الموضعُ الذي وقعَ فيه العطل.**
|
| ⚠️ و`last_workspace_id` فارغٌ: لا شيءَ في مسارِ الطالبِ يكتبُ ذلك العمود،
| فسياقُه `null` في الإنتاجِ دائماً — وتركيبةٌ تختمُه تقيسُ شخصاً آخر.
*/
it('answers the student the schedule, not the label', function (): void {
    $course = mislabelledCourse();

    $student = User::factory()->create(['last_workspace_id' => null]);
    $this->createEnrollment($this->workspace, $course, $student);

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    $this->getJson("/api/v1/courses/{$course->uuid}/curriculum")
        ->assertOk()
        ->assertJsonPath('course.has_sessions', true)
        ->assertJsonPath('course.course_type', Course::TYPE_RECORDED);
});
