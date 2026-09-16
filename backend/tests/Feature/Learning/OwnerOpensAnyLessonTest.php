<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **مالكُ المنصّةِ كانَ الحسابَ الوحيدَ الذي لا يفتحُ درساً — وقُرِّرَ فتحُه.**
|
| `Gate::before` يُمرِّرُ `users.is_super_admin` فوقَ كلِّ سياسةٍ في المنتَج.
| وبابانِ اثنانِ يسألانِ العضويّةَ مباشرةً لا سياسةً — صفحةُ الدرسِ
| (`showLessonForViewer`) والفيديو (`IssuePlaybackGrant::mayWatch`) — فكانا
| الاستثناءَ الوحيد: المالكُ يفتحُ كلَّ شاشةٍ في المنصّةِ ويُردُّ عن صفحةِ
| الدرسِ وحدَها. بلاغٌ من الإنتاجِ ٢٠٢٦-٠٩-١٦، وقرارُ المالكِ فتحُهما.
|
| ⚠️ **والشقّانِ يُقاسانِ معاً عن قصد.** CLAUDE.md يكتبُها: البابانِ يتحرّكانِ
| معاً أو تُفتَحُ الصفحةُ ويرفضُ الفيديو — وهو بعينِه عطبُ ٠١٨، حينَ قالَ
| `mayWatch()` نعم وقالَ التسلسلُ لا، فصارَ تسجيلٌ **مدفوعٌ** لا يُفتَحُ أبداً.
| شقٌّ واحدٌ منهما يمرُّ على نصفِ إصلاح.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $section = Section::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    $this->lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'type' => 'video',
        'status' => 'published',
    ]);

    /*
    | المالكُ كما هو في الإنتاج: `is_super_admin` وحدَه — بلا تسجيلٍ، وبلا صفٍّ
    | في `workspace_members`، وبلا ملفِّ تدريس. مقيسٌ على الإنتاج ٢٠٢٦-٠٩-١٦:
    | صفرُ صفوفٍ في الاثنَين. وتجهيزةٌ تمنحُه أيَّها تقيسُ شخصاً آخَر.
    |
    | ⚠️ و`forceFill` لا `create([...])`: `last_workspace_id` في `$guarded`،
    | فالإسنادُ الجمليُّ يُسقِطُه في صمتٍ ويبني الحسابَ بسياقٍ فارغ.
    */
    $this->platformOwner = User::factory()->create();
    $this->platformOwner->forceFill(['is_super_admin' => true, 'last_workspace_id' => null])->save();
});

/** Drop the cached resolution so the next request resolves as the caller would. */
function forgetContextForOwnerWalk(): void
{
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
}

it('opens the lesson page for the platform owner, who is enrolled in nothing', function (): void {
    Sanctum::actingAs($this->platformOwner);
    forgetContextForOwnerWalk();

    $this->getJson("/api/v1/learn/lessons/{$this->lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('lesson.uuid', (string) $this->lesson->uuid)
        ->assertJsonPath('can_access', true);
});

it('agrees at the video door, so the page does not open over a refused file', function (): void {
    Sanctum::actingAs($this->platformOwner);
    forgetContextForOwnerWalk();

    $may = app(IssuePlaybackGrant::class)->mayWatch($this->lesson, $this->platformOwner);

    expect($may)->toBeTrue();

    // والصيغةُ الجمليّةُ — شاشةُ المنهجِ تمرُّ منها، وشرطٌ في إحداهما وحدَها ثقب.
    $many = app(IssuePlaybackGrant::class)->mayWatchMany([$this->lesson], $this->platformOwner);

    expect($many[(int) $this->lesson->getKey()] ?? false)->toBeTrue();
});

/*
| ⚠️ **والضابطُ على المعنى**: الفتحُ للمالكِ وحدَه، لا لكلِّ من لا تسجيلَ له.
| بلا هذا الشقِّ يمرُّ بناءٌ يفتحُ البابَ للجميع.
*/
it('still refuses a signed-in stranger, and names WHICH refusal it is', function (): void {
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);
    forgetContextForOwnerWalk();

    $this->getJson("/api/v1/learn/lessons/{$this->lesson->uuid}")
        ->assertNotFound()
        // ⛔ الرمزُ هو ما يصلُ إلى الشاشة: بدونَه يطبعُ `userMessage()`
        // «العنصر المطلوب غير موجود أو حُذف» عن درسٍ قائمٍ لا يخصُّ القارئ.
        ->assertJsonPath('code', 'not_enrolled');
});
