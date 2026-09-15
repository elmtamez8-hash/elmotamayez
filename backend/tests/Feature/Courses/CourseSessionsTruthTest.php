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

    // ⚠️ تحميةٌ أوّلاً: ذاكرةُ صلاحيّاتِ spatie تُقرَأُ من الجدولِ في أوّلِ طلب،
    // فقياسٌ باردٌ مقابلَ قياسٍ دافئٍ يُظهِرُ فرقاً أربعةَ استعلاماتٍ لا علاقةَ
    // له بعددِ الصفوف — وهو ما يُسقِطُ المقارنةَ بسببٍ لا تقصدُه.
    $this->getJson('/api/v1/courses')->assertOk();

    [$count, $payload] = countingQueries(fn () => $this->getJson('/api/v1/courses')->assertOk()->json());

    expect($payload['data'])->toHaveCount(3);

    foreach ($payload['data'] as $row) {
        expect(array_keys($row))->toContain('has_sessions')
            ->and($row['has_sessions'])->toBeTrue();
    }

    /*
    | ⛔ **مقارنةٌ بين حجمَين، لا سقفٌ مكتوبٌ بالرقم.** كانَ الشرطُ
    | `<= 15` وقِيسَ الفعليُّ **٨** — وقراءةٌ لكلِّ صفٍّ كانت ستُعطي ١١، أي
    | تمرُّ. سقفٌ لا يعضُّ هو ضابطٌ أخضرُ أبداً، وهو ما يحرسُ منه هذا المستودعُ
    | بقياسِ صفوفٍ قليلةٍ ثمّ صفوفٍ أكثرَ وتوكيدِ أنّ الفرقَ لا ينمو.
    */
    foreach (range(1, 7) as $ignored) {
        mislabelledCourse();
    }

    [$larger, $biggerPayload] = countingQueries(fn () => $this->getJson('/api/v1/courses')->assertOk()->json());

    expect($biggerPayload['data'])->toHaveCount(10)
        ->and($larger)->toBe($count);
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

/*
| ⛔ **والطالبُ المختومُ بمساحةِ مدرّسٍ آخَر، وهو الشكلُ الذي كانَ هذا الملفُّ
| أعمى عنه — فمرَّ العطلُ إلى الإنتاج.**
|
| `has_sessions` يُقرَأُ بـ`withExists`، وذلكَ يبني الاستعلامَ الفرعيَّ من
| `ClassSession::newQuery()` **بنطاقاتِه** — فيصيرُ الشرطُ مساحةَ القارئِ لا
| مساحةَ الكورس. و`WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`،
| المختومِ على كلِّ طالبٍ أضافَه مدرّسٌ أو دعوةٌ أو بذرةٌ إلى مساحة (ستّةُ صفوفٍ
| بدورِ `student` قِيسَت على قاعدةٍ حقيقيّة).
|
| فالشقُّ الوحيدُ الذي كانَ في هذا الملفِّ — طالبٌ بسياقٍ فارغ — أخضرُ على
| البناءِ المعطوب، لأنّ `WorkspaceScope::apply()` لا يُضيفُ شرطاً حينَ يكونُ
| المعرّفُ `null`. وتعليقُه «لا شيءَ في مسارِ الطالبِ يكتبُ ذلك العمود» صحيحٌ
| عن الطالبِ المسجِّلِ نفسَه وخطأٌ في العموم.
|
| ⚠️ **و`forceFill` لا `create([...])`**: `last_workspace_id` في
| `User::$guarded`، فالإسنادُ الجَماعيُّ يُسقِطُه في صمتٍ ويعيدُ بناءَ الطالبِ
| ذي السياقِ الفارغِ — أي يقيسُ الشقَّ الذي فوقَه مرّةً ثانية.
|
| **كيفَ يمسك**: احذفْ `withoutGlobalScope(WorkspaceScope::class)` من
| `Course::classSessions()` ⇒ يسقطُ بـ«false بدل true».
*/
it('answers a student stamped into another workspace, on both doors', function (): void {
    $course = mislabelledCourse();

    [$otherWorkspace] = $this->createWorkspaceWithOwner();

    $student = User::factory()->create();
    $student->forceFill(['last_workspace_id' => $otherWorkspace->getKey()])->save();

    $this->createEnrollment($this->workspace, $course, $student);

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    $this->getJson("/api/v1/courses/{$course->uuid}/curriculum")
        ->assertOk()
        ->assertJsonPath('course.has_sessions', true);

    /*
    | ⛔ **والتبويبُ الذي يظهرُ يجبُ أن يفتحَ على شيء.** الطريقانِ اللذانِ
    | يملآنِه كانا يربطانِ `{course}` ربطاً ضمنيّاً، فيمرّانِ بالنطاقِ نفسِه:
    | **٤٠٤** لهذا الطالبِ بالضبط — قِيسَ. ولم يظهرْ قبلُ لأنّ الشاشةَ لم تكنْ
    | ترسمُ التبويبَ أصلاً؛ بابانِ مقفولانِ خلفَ بابٍ مقفول.
    |
    | وصفراً من الصفوفِ ليسَ جواباً كذلك: تصحيحُ الربطِ وحدَه ردَّ ٢٠٠ وقائمةً
    | فارغةً، لأنّ استعلامَ `ClassSession` نفسَه تحتَ النطاق. الطبقاتُ الثلاثُ
    | تُقاسُ هنا معاً.
    */
    $this->getJson("/api/v1/courses/{$course->uuid}/next-session")->assertOk();

    $sessions = $this->getJson("/api/v1/courses/{$course->uuid}/sessions")->assertOk();

    expect($sessions->json('data'))->toHaveCount(1);
});
