<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\LessonProgress;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| ⛔ **أبوابُ الدرسِ حينَ يكونُ `users.last_workspace_id` مساحةً أخرى.**
|
| `StudentCrossWorkspaceDoorsTest` يقودُ أبوابَ الكورسِ والشراء؛ وهذه أبوابُ
| الدرسِ نفسِه، وكانت الفجوةَ الوحيدةَ الباقيةَ بعدَ إصلاحِ العائلة: ربطُها
| تغيّرَ بالآليّةِ نفسِها ولم يُقَدْ عبرَ المسارِ ولا مرّة.
|
| ⚠️ **وهي تحتاجُ شجرةَ منهجٍ كاملةً**، ولذلك لم تدخلْ ذاك الملفّ:
| `CurriculumFixtures::curriculumTree()` يبنيها، وكلُّ ما تضيفُه هذه الحالاتُ
| هو **الختمُ على مساحةٍ ثانية** — وهو السطرُ الذي يُشغِّلُ العطب.
|
| ⚠️ **ثلاثةُ شروطٍ تجعلُ التركيبةَ تقيسُ شيئاً**: الختمُ بـ`forceFill` (العمودُ
| في `$guarded`)، وعلى مساحةٍ **غيرِ** مساحةِ الكورس، و`forgetInstance` لا
| `forget()` — الأخيرةُ تُثبِّتُ السياقَ على العدمِ فتقيسُ إنساناً لا يُنتِجُه
| الإنتاج.
*/
beforeEach(function (): void {
    /*
    | ⚠️ **غيرُ متسلسلةٍ عن قصد.** هذه الحالاتُ عن الربطِ والسياسةِ لا عن بوّابةِ
    | التسلسل، وشجرةٌ متسلسلةٌ تردُّ `not_visible` على «أتمِمْ» فتقيسُ حارساً
    | آخرَ — وتُخفي ما جاءت له.
    */
    $tree = $this->curriculumTree(sequential: false);

    $this->student = $tree['student'];
    $this->course = $tree['course'];
    $this->enrollment = $tree['enrollment'];
    $this->lessons = $tree['lessons'];

    // مساحةٌ ثانيةٌ يُختَمُ عليها الطالبُ، وليست مساحةَ الكورس.
    [$this->elsewhere] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّةٌ أخرى']);
    $this->student->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();
});

/** يفتحُ البابَ كما تفتحُه الصفحة. */
function asStampedLearner(): void
{
    Sanctum::actingAs(test()->student);
    app()->forgetInstance(WorkspaceContext::class);
}

/**
 * الدرسُ المفتوحُ غيرُ المُتَمّ في الشجرة.
 *
 * ⚠️ بالمفتاحِ المُسمّى لا بأوّلِ ما يوافقُ شرطاً: الشجرةُ تحملُ مسوّدةً ومؤرشَفاً
 * وامتحاناً، و«أوّلُ درسٍ» تلتقطُ ما يتغيّرُ ترتيبُه مع أوّلِ إضافةٍ للتركيبة.
 */
function openLesson(): Lesson
{
    return test()->lessons['open'];
}

it('opens a lesson through the enrolment, at another teacher', function (): void {
    asStampedLearner();

    $lesson = openLesson();

    $this->getJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/lessons/'.$lesson->uuid)
        ->assertOk()
        ->assertJsonPath('lesson.uuid', (string) $lesson->uuid);
});

it('opens the same lesson from the viewer door, at another teacher', function (): void {
    asStampedLearner();

    $lesson = openLesson();

    $this->getJson('/api/v1/learn/lessons/'.$lesson->uuid)
        ->assertOk()
        ->assertJsonPath('lesson.uuid', (string) $lesson->uuid);
});

it('marks a lesson complete, at another teacher', function (): void {
    /*
    | ⚠️ **وهذا البابُ يمرُّ من `EnrollmentPolicy::completeLessons()`** — وهي التي
    | كانت تسألُ عن المساحةِ فوقَ فرعِ المِلكيّة، فتمنعُ صاحبَ التسجيلِ من تسجيلِه.
    */
    asStampedLearner();

    $lesson = openLesson();

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/lessons/'.$lesson->uuid.'/complete')
        ->assertOk();

    // التوكيدُ على الكتابةِ التي يقومُ بها هذا الباب، لا على حسابِ النسبةِ بعدَه.
    expect(LessonProgress::query()->withoutWorkspaceScope()
        ->where('enrollment_id', $this->enrollment->getKey())
        ->where('lesson_id', $lesson->getKey())
        ->where('status', 'completed')
        ->count())->toBe(1);
});

it('resets the whole course, at another teacher', function (): void {
    // `resetProgress` — الفرعُ الثالثُ الذي حُذِفَ منه فحصُ المساحة.
    asStampedLearner();

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')
        ->assertOk();
});
