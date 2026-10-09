<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Support\TrialLessonRule;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;

/*
| Spec 040 · research R3 — the trial rule has two faces: `refusalFor()` when the
| teacher marks, `scopeEligible()` when a public reader shows or streams. If they
| disagreed, the teacher page would advertise a trial the door refuses, or the
| door would serve a lesson the teacher was told they could not mark. So every
| case below asks BOTH and demands they agree.
*/

function trialEligible(Lesson $lesson): bool
{
    return TrialLessonRule::scopeEligible(
        Lesson::query()->withoutWorkspaceScope()->whereKey($lesson->getKey()),
    )->exists();
}

function trialRefusal(Course $course, Lesson $lesson): ?string
{
    return TrialLessonRule::refusalFor($course, Lesson::query()->withoutWorkspaceScope()->findOrFail($lesson->getKey()));
}

it('accepts a published embed, on both faces', function (): void {
    [$course, $lesson] = trialFixture();

    expect(trialRefusal($course, $lesson))->toBeNull()
        ->and(trialEligible($lesson))->toBeTrue();
});

it('accepts an uploaded video ready on the streaming provider, on both faces', function (): void {
    [$course, $lesson] = trialFixture(['type' => 'video', 'external_url' => null], asset: []);

    expect(trialRefusal($course, $lesson))->toBeNull()
        ->and(trialEligible($lesson))->toBeTrue();
});

it('refuses on both faces, naming the reason', function (array $lesson, ?array $asset, string $reason): void {
    [$course, $model] = trialFixture($lesson, $asset);

    expect(trialRefusal($course, $model))->toContain($reason)
        ->and(trialEligible($model))->toBeFalse();
})->with([
    'an article' => [['type' => 'article', 'external_url' => null], null, 'الحصة التجريبية فيديو'],
    'high value' => [['is_high_value' => true], null, 'عالية القيمة'],
    'a video with no upload' => [['type' => 'video', 'external_url' => null], null, 'ارفع فيديو'],
    'a legacy local video' => [['type' => 'video', 'external_url' => null], ['provider' => 'local'], 'بالطريقة القديمة'],
    'an audio asset on a video lesson' => [['type' => 'video', 'external_url' => null], ['kind' => 'audio'], 'ارفع فيديو'],
]);

it('refuses a session recording, and a lesson held back for a session, on both faces', function (string $column): void {
    [$course, $lesson, $workspace, $teacher] = trialFixture();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($lesson, $teacher, $column): void {
        $session = ClassSession::factory()->create([
            'workspace_id' => $lesson->workspace_id,
            'teacher_profile_id' => $teacher->getKey(),
        ]);

        // `forceFill`: neither column is mass-assignable from a test array.
        Lesson::query()->withoutWorkspaceScope()->findOrFail($lesson->getKey())
            ->forceFill([$column => $session->getKey()])->save();
    });

    expect(trialRefusal($course, $lesson))->not->toBeNull()
        ->and(trialEligible($lesson))->toBeFalse();
})->with(['class_session_id', 'release_session_id']);

it('refuses a lesson narrowed to a group, on both faces', function (): void {
    [$course, $lesson, $workspace] = trialFixture();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($course, $lesson): void {
        $cohort = Cohort::factory()->create([
            'workspace_id' => $course->workspace_id,
            'course_id' => $course->getKey(),
            'created_by' => $course->created_by,
            'name' => 'مجموعة السبت',
        ]);

        LessonCohortScope::query()->create([
            'workspace_id' => $lesson->workspace_id,
            'lesson_id' => $lesson->getKey(),
            'cohort_id' => $cohort->getKey(),
        ]);
    });

    expect(trialRefusal($course, $lesson))->toContain('مقصور على مجموعة')
        ->and(trialEligible($lesson))->toBeFalse();
});

it('refuses a lesson from another course at marking', function (): void {
    [$course] = trialFixture();
    [, $foreign] = trialFixture();

    expect(trialRefusal($course, $foreign))->toBe('اختر درساً من هذا الكورس.');
});

it('marks before publishing, and shows only once published', function (): void {
    [$course, $lesson] = trialFixture(['status' => 'draft']);

    expect(trialRefusal($course, $lesson))->toBeNull()
        ->and(trialEligible($lesson))->toBeFalse();
});

it('shows nothing while the section is unpublished', function (): void {
    [, $lesson] = trialFixture(sectionStatus: 'draft');

    expect(trialEligible($lesson))->toBeFalse();
});

it('marks an upload still processing, and shows it only when ready', function (): void {
    [$course, $lesson] = trialFixture(['type' => 'video', 'external_url' => null], ['status' => 'processing', 'ready_at' => null]);

    expect(trialRefusal($course, $lesson))->toBeNull()
        ->and(trialEligible($lesson))->toBeFalse();
});
