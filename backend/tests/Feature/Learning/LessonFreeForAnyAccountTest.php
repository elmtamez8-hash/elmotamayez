<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ «مفتوح مجّاناً» كانَ جملةً بلا طريق (بلاغُ ٢٠٢٦-٠٩-٢٤)، وقرارُ المالكِ:
| **أيُّ حسابٍ مسجَّلِ الدخولِ يفتحُ درساً مفتوحاً**.
|
| الفيديو كانَ مفتوحاً له منذ ٠٣٢ (`IssuePlaybackGrant::mayWatch()`)، والصفحةُ
| التي تُشغِّلُه (`/learn/lessons/{uuid}`) تطلبُ تسجيلاً — بابانِ يختلفان.
| فكلُّ حالةٍ هنا تسألُ البابَينِ معاً، وشقٌّ واحدٌ منهما يمرُّ على نصفِ إصلاح.
|
| ⚠️ **وبالشكلَينِ اللذَينِ يُنتجُهما الإنتاج**: طالبٌ سجّلَ بنفسِه (سياقٌ فارغ)،
| وطالبٌ مختومٌ على مساحةِ مدرّسٍ آخر (`forceFill` — العمودُ في `$guarded`).
| الثاني هو من يُسقِطُ علاقةً مُقيَّدةً إلى `null` فيُقرَأُ درسٌ منشورٌ مخفيّاً.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->elsewhere] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّةٌ أخرى']);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): void {
        $this->course = Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
        ]);

        $section = Section::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'status' => ContentStatus::Published,
        ]);
        $chapter = Chapter::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'section_id' => $section->getKey(),
            'status' => ContentStatus::Published,
        ]);
        $draftChapter = Chapter::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'section_id' => $section->getKey(),
            'status' => ContentStatus::Draft,
        ]);

        $make = fn (array $extra, ?Chapter $in = null): Lesson => Lesson::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'section_id' => $section->getKey(),
            'chapter_id' => ($in ?? $chapter)->getKey(),
            'type' => 'article',
            'content' => 'FREE_LESSON_BODY',
            'status' => ContentStatus::Published,
            'is_free' => false,
            'is_preview' => false,
            ...$extra,
        ]);

        $this->free = $make(['is_free' => true]);
        $this->preview = $make(['is_preview' => true]);
        $this->paid = $make([]);
        $this->freeDraft = $make(['is_free' => true, 'status' => ContentStatus::Draft]);
        $this->freeInDraftChapter = $make(['is_free' => true], $draftChapter);
        $this->freeForOneGroup = $make(['is_free' => true]);

        $cohort = Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'created_by' => $this->owner->getKey(),
        ]);

        LessonCohortScope::create([
            'workspace_id' => $this->workspace->getKey(),
            'lesson_id' => $this->freeForOneGroup->getKey(),
            'cohort_id' => $cohort->getKey(),
        ]);
    });

    $this->reader = User::factory()->create(['platform_role' => 'student']);
});

/** Signs the reader in the way a real request would resolve them. */
function freeLessonReader(bool $stamped): User
{
    $reader = test()->reader;

    if ($stamped) {
        $reader->forceFill(['last_workspace_id' => test()->elsewhere->getKey()])->save();
    }

    Sanctum::actingAs($reader);
    app()->forgetInstance(WorkspaceContext::class);

    return $reader;
}

dataset('reader shapes', [
    'self-registered (no context)' => false,
    'stamped on another teacher' => true,
]);

it('opens a free lesson for a signed-in account with no enrolment, at both doors', function (bool $stamped): void {
    $reader = freeLessonReader($stamped);

    foreach ([$this->free, $this->preview] as $lesson) {
        $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")
            ->assertOk()
            ->assertJsonPath('can_access', true)
            ->assertJsonPath('lesson.content', 'FREE_LESSON_BODY')
            // No enrolment behind it: the page draws no completion control and
            // the completion door has no enrolment uuid to be called with.
            ->assertJsonPath('enrollment_uuid', null);

        expect(app(IssuePlaybackGrant::class)->mayWatch($lesson->fresh(), $reader))->toBeTrue();
    }
})->with('reader shapes');

it('creates no enrolment by opening a free lesson', function (bool $stamped): void {
    freeLessonReader($stamped);

    $this->getJson("/api/v1/learn/lessons/{$this->free->uuid}")->assertOk();

    expect(Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->reader->getKey())
        ->count())->toBe(0);
})->with('reader shapes');

it('still refuses a lesson that is not free', function (bool $stamped): void {
    freeLessonReader($stamped);

    $this->getJson("/api/v1/learn/lessons/{$this->paid->uuid}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_enrolled');
})->with('reader shapes');

it('keeps an unpublished free lesson closed, at both doors', function (bool $stamped): void {
    $reader = freeLessonReader($stamped);

    foreach ([$this->freeDraft, $this->freeInDraftChapter] as $lesson) {
        $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")->assertNotFound();

        expect(app(IssuePlaybackGrant::class)->mayWatch($lesson->fresh(), $reader))->toBeFalse();
    }
})->with('reader shapes');

it('keeps a free lesson narrowed to one group closed to everybody else, at both doors', function (bool $stamped): void {
    $reader = freeLessonReader($stamped);

    $this->getJson("/api/v1/learn/lessons/{$this->freeForOneGroup->uuid}")->assertNotFound();

    expect(app(IssuePlaybackGrant::class)->mayWatch($this->freeForOneGroup->fresh(), $reader))->toBeFalse();
})->with('reader shapes');

it('opens nothing to a visitor with no account', function (): void {
    $this->getJson("/api/v1/learn/lessons/{$this->free->uuid}")->assertUnauthorized();
});
