<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Learning\Support\LessonGate;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ٠٢٦ · US1 — «لمن هذا العنصر»، من الشجرةِ إلى الشاشة.
|
| ⚠️ **التوكيدُ على الحمولةِ لا على الحكمِ وحدَه.** `LessonGate` يجيبُ برمزٍ،
| و`CurriculumResource` هو الذي يُسقِطُ الصفّ — فاختبارٌ يقفُ عندَ الرمزِ أخضرُ
| على بناءٍ يعرضُ الصفَّ مقفولاً ويقولُ للطالبِ «هذا ليسَ لمجموعتِك» عن شيءٍ ما
| كانَ ليعرفَ بوجودِه.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();
});

/** المنهجُ كما يقرؤُه الطالبُ فعلاً — بحسابِه، وبسياقٍ يُحَلُّ من جديد. */
function curriculumTitles(User $student, Enrollment $enrollment): array
{
    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    $payload = test()->getJson('/api/v1/courses/'.$enrollment->course->uuid.'/curriculum')
        ->assertOk()
        ->json('sections');

    $titles = [];

    foreach ($payload as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                $titles[] = $lesson['title'];
            }
        }
    }

    return $titles;
}

it('does not put a narrowed item on the screen of a student in another group', function (): void {
    $titles = curriculumTitles($this->tree['student'], $this->tree['enrollment']);

    expect($titles)->toContain('المشتَركُ الأوّل')
        ->and($titles)->toContain('المشتَركُ الأخير')
        ->and($titles)->not->toContain('مقصورٌ على مجموعةٍ أخرى');
});

/*
| ⛔ **وهذا هو نصفُ المواصفةِ الخطير.** العنصرُ المقصورُ يقفُ في الترتيبِ بينَ
| اثنَين، فإن لم يُستَثنَ من استعلامِ «ما قبلَه» في `LessonGate::for()` — وهو
| استعلامٌ **لا ينادي `progressEligible()` إطلاقاً** — قِيلَ للطالبِ «أكمِلْ
| درساً» عن درسٍ غيرِ موجودٍ في شاشتِه، إلى الأبد.
|
| **كيفَ يمسك**: احذفْ `whereNotIn(... lesson_cohort_scopes ...)` من ذلكَ
| الاستعلامِ وحدَه ⇒ تسقطُ هذه الحالةُ برمزِ `sequence`، وتبقى التي فوقَها
| خضراء.
*/
it('does not let a narrowed item stand in front of the one after it', function (): void {
    $access = LessonGate::for($this->tree['enrollment'], $this->tree['lessons']['shared_last']->fresh());

    expect($access->allowed)->toBeTrue(
        'المشتَركُ الأخير مقفولٌ بسبب: '.(string) $access->code.' — '.(string) $access->message,
    );
});

it('opens it for a student who is in the group it was narrowed to', function (): void {
    $insider = User::factory()->create();

    CohortMembership::query()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'cohort_id' => $this->tree['theirs']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $insider->getKey(),
        'joined_at' => now(),
    ]);

    $enrollment = Enrollment::create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $insider->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    expect(curriculumTitles($insider, $enrollment))->toContain('مقصورٌ على مجموعةٍ أخرى');
});

/*
| ⛔ **وطالبٌ لا مجموعةَ له يرى «للجميع» وحدَها — ولا يُقفَلُ عليه المنهج.**
| ٠٣٤ · FR-015 تُلغي قفلَ «لستَ في أيِّ مجموعة» نصّاً، وقد بيتَ ذلكَ القفلُ
| رجعيّاً على أربعةِ تسجيلاتٍ من أربعةٍ على الإنتاج. فالتضييقُ يُخفي المقصورَ
| ولا يمسُّ المشترَك.
*/
it('shows a student with no group at all the shared items, and only those', function (): void {
    $loner = User::factory()->create();

    $enrollment = Enrollment::create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $loner->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $titles = curriculumTitles($loner, $enrollment);

    expect($titles)->toContain('المشتَركُ الأوّل')
        ->and($titles)->toContain('المشتَركُ الأخير')
        ->and($titles)->not->toContain('مقصورٌ على مجموعةٍ أخرى');
});

/*
| المنقولُ يرى مجموعتَه الجديدة: العضويّةُ المفتوحةُ الآنَ هي السؤال، لا «كانَ
| عضواً يوماً» — وذاكَ سؤالٌ آخرُ له بيتُه (قراءةُ خيطِ المجموعةِ القديمة).
*/
it('follows a transferred student to the group they are in now', function (): void {
    /** @var CohortMembership $open */
    $open = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->tree['student']->getKey())
        ->firstOrFail();

    // ⚠️ `closed_slot` يُكتَبُ معَ الإغلاقِ وإلّا اصطدمَ الصفُّ الجديدُ بالقديمِ
    // على الفهرسِ الفريد: الصفرُ سنتينلٌ ما دامَ الصفُّ قائماً.
    $open->forceFill(['closed_at' => now(), 'closed_slot' => $open->getKey()])->save();

    CohortMembership::query()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'cohort_id' => $this->tree['theirs']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $this->tree['student']->getKey(),
        'joined_at' => now(),
    ]);

    expect(curriculumTitles($this->tree['student'], $this->tree['enrollment']))
        ->toContain('مقصورٌ على مجموعةٍ أخرى');
});

it('shows an item narrowed to two groups to whoever is in either of them', function (): void {
    LessonCohortScope::query()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'lesson_id' => $this->tree['lessons']['scoped_away']->getKey(),
        'cohort_id' => $this->tree['mine']->getKey(),
    ]);

    expect(curriculumTitles($this->tree['student'], $this->tree['enrollment']))
        ->toContain('مقصورٌ على مجموعةٍ أخرى');
});

/*
| ⚠️ **والبابُ المباشرُ يُرفَضُ بجملةِ «غير متاح» لا بجملةٍ تكشفُ الوجود.**
| الصفُّ غيرُ موجودٍ في المنهج، فمَن يبلغُ هذا العنوانَ إنّما طرَقَه؛ وجملةٌ
| تقولُ «ليسَ لمجموعتِك» تُخبِرُه بما ما كانَ ليعرفَه.
*/
it('refuses the item’s own page without saying that it exists for somebody else', function (): void {
    Sanctum::actingAs($this->tree['student']);
    app()->forgetInstance(WorkspaceContext::class);

    /** @var Lesson $lesson */
    $lesson = $this->tree['lessons']['scoped_away'];

    $response = $this->getJson('/api/v1/learn/lessons/'.$lesson->uuid);

    $response->assertNotFound();

    expect($response->json('message'))->not->toContain('مجموعة');
});

it('answers the same code from both forms of the gate', function (): void {
    /** @var Lesson $lesson */
    $lesson = $this->tree['lessons']['scoped_away'];

    $one = LessonGate::for($this->tree['enrollment'], $lesson->fresh());
    $many = LessonGate::forTree($this->tree['enrollment'])[(int) $lesson->getKey()] ?? null;

    expect($one->code)->toBe(LessonAccess::OUT_OF_SCOPE)
        ->and($many)->not->toBeNull()
        ->and($many->code)->toBe($one->code)
        ->and($many->message)->toBe($one->message);
});
