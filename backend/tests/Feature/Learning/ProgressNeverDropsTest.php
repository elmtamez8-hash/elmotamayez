<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Support\CourseProgress;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ⛔ ٠٢٦ · FR-013 · FR-014 · SC-003 — **نسبةُ الطالبِ لا تنزلُ أبداً.**
|
| وهذه عائلةُ أسوأِ عطلٍ يسجّلُه هذا المستودعُ من ستّةِ أبواب: عنصرٌ يدخلُ
| المقامَ ولا يستطيعُ الطالبُ إتمامَه يُبقيه تحتَ ١٠٠٪ **للأبد**، فلا يقعُ حدثُ
| إتمامِ الكورسِ ولا تصدرُ شهادةٌ أبداً. والعنصرُ المربوطُ بحصّةٍ هو البابُ
| السابع: يُنشَرُ اليومَ ويظهرُ بعدَ أسبوع.
|
| ⛔ **والشرطُ «مربوطٌ بحصّة» لا «لم يُفرَجْ عنه بعد» — وهذا هو الفرقُ بينَ
| FR-013 وFR-013أ حرفيّاً.** خاصّيّةُ العنصرِ لا حالُ اللحظة: بالثاني يقفزُ
| المقامُ من ٢ إلى ٣ لحظةَ تسليمِ الحصّة، فينزلُ كلُّ طالبٍ كانَ على ١٠٠٪ إلى
| ٦٦٪ — بلا أن يفعلَ شيئاً، وبلا أن يُسحَبَ منه شيءٌ أتمَّه.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();

    // الطالبُ أتمَّ كلَّ ما في مقامِه: المشتركانِ اثنانِ، والمقصورُ خارجَ
    // المقامِ بخاصّيّتِه (US1).
    $this->tree['enrollment']->progress()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'lesson_id' => $this->tree['lessons']['shared_last']->getKey(),
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
    ]);
});

/** يُنشئُ درساً منشوراً قابلاً للإتمامِ مربوطاً بحصّةٍ من هذا الكورس. */
function lessonAwaiting(array $sessionState = []): array
{
    $tree = test()->tree;

    return app(WorkspaceContext::class)->forWorkspace($tree['workspace'], function () use ($tree, $sessionState): array {
        $session = ClassSession::factory()->create([
            'workspace_id' => $tree['workspace']->getKey(),
            'course_id' => $tree['course']->getKey(),
            ...$sessionState,
        ]);

        $lesson = Lesson::create([
            'workspace_id' => $tree['workspace']->getKey(),
            'course_id' => $tree['course']->getKey(),
            'section_id' => $tree['chapter']->section_id,
            'chapter_id' => $tree['chapter']->getKey(),
            'uuid' => Str::uuid(),
            'title' => 'ورقةُ حصّةِ الأحد',
            // ⚠️ منشورٌ ومن نوعٍ قابلٍ للإتمام، وإلّا فهو خارجُ المقامِ لسببٍ
            // آخرَ أصلاً والحالةُ خضراءُ بلا أن تقيسَ شيئاً.
            'type' => 'article',
            'status' => ContentStatus::Published,
            'order' => 9,
            'content' => 'نصّ',
        ]);

        $lesson->forceFill(['release_session_id' => $session->getKey()])->save();

        return ['session' => $session, 'lesson' => $lesson];
    });
}

function progressNow(): int
{
    $enrollment = test()->tree['enrollment']->fresh();

    return CourseProgress::percentage(
        CourseProgress::completed($enrollment),
        CourseProgress::total($enrollment),
    );
}

it('has the student at a hundred before anything is published', function (): void {
    expect(progressNow())->toBe(100);
});

it('keeps them at a hundred when an item awaiting its session is published', function (): void {
    lessonAwaiting();

    CourseStructureChanged::dispatch($this->tree['course']);

    expect(progressNow())->toBe(100)
        ->and($this->tree['enrollment']->fresh()->progress_pct)->toBe(100);
});

/*
| ⛔ **وبعدَ التسليمِ كذلك** (FR-013). العنصرُ المربوطُ بحصّةٍ خارجَ المقامِ
| **إلى الأبدِ لا حتّى الإفراج**: الطالبُ يفتحُه ويقرؤُه، ولكنّه لا يُحاسَبُ
| على إتمامِه، لأنّ دخولَه المقامَ بعدَ ذلكَ يعني نزولَ كلِّ مَن بلغَ ١٠٠٪.
|
| ⚠️ **ولا شيءَ يقعُ عندَ التسليمِ من تلقاءِ نفسِه** — ولا يجبُ أن يقع، لأنّ
| المقامَ لم يتغيّر. فالحالةُ تُجبِرُ إعادةَ الحساب، وإلّا كانت خضراءَ على أيِّ
| بناءٍ كانَ: لا شيءَ يُعيدُ الحسابَ فلا شيءَ ينزل.
|
| **كيفَ يمسك**: اجعلْ شرطَ `progressEligible()` «لم يُفرَجْ عنه بعد» بدلَ
| «مربوطٌ بحصّة» ⇒ يسقطُ هذا الشقُّ وحدَه بـ«٦٦ ≠ ١٠٠»، ويبقى الذي فوقَه أخضر.
*/
it('keeps them at a hundred after that session has been delivered', function (): void {
    $built = lessonAwaiting();

    CourseStructureChanged::dispatch($this->tree['course']);

    $built['session']->forceFill([
        'status' => ClassSessionStatus::Completed,
        'delivered_at' => now(),
    ])->save();

    // إجباراً، فالتسليمُ لا يُطلِقُ إعادةَ حساب — وهي الحالةُ التي تجعلُ هذا
    // الشقَّ يقيسُ شيئاً بدلَ أن يكونَ صامتاً.
    CourseStructureChanged::dispatch($this->tree['course']->fresh());

    expect(progressNow())->toBe(100)
        ->and($this->tree['enrollment']->fresh()->progress_pct)->toBe(100);
});
