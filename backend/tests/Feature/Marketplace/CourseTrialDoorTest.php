<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Events\PlaybackSustained;
use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Spec 040 · US1 + US6 — the guest door to a course's «حصة تجريبية».
|
| The door takes a COURSE and serves its current trial, or the one 404. It never
| writes a grant, fires an event or records progress (FR-011), and it is the only
| guest path to an uploaded video's bytes — signed by the real Bunny signer here,
| which makes no network call to sign.
*/

beforeEach(function (): void {
    config([
        'media.bunny.pull_zone' => 'vz-trial-test',
        'media.bunny.security_key' => 'test-security-key',
        'media.trial_providers' => ['bunny'],
    ]);
});

/** @param array<string, mixed> $lesson */
function markedTrial(array $lesson = [], ?array $asset = null, string $sectionStatus = 'published'): array
{
    [$course, $model, $workspace, $teacher] = trialFixture($lesson, $asset, $sectionStatus);

    Course::query()->withoutWorkspaceScope()->whereKey($course->getKey())
        ->update(['trial_lesson_id' => $model->getKey()]);

    return [$course->fresh(), $model, $workspace, $teacher];
}

function trialDoor(Course $course, string $suffix = ''): TestResponse
{
    return test()->getJson("/api/v1/marketplace/courses/{$course->slug}/trial{$suffix}");
}

it('serves an embedded trial to a guest, even when the lesson is not marked «open»', function (): void {
    [$course, $lesson] = markedTrial(['is_preview' => false, 'is_free' => false]);
    $this->asGuest();

    trialDoor($course)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.uuid', (string) $lesson->uuid)
        ->assertJsonPath('data.kind', 'embed')
        ->assertJsonPath('data.embed_url', $lesson->external_url)
        ->assertJsonMissingPath('data.playback');
});

it('serves an uploaded trial as our own stream route, and the stream redirects to a short signed link', function (): void {
    [$course] = markedTrial(['type' => 'video', 'external_url' => null], asset: ['provider_asset_id' => 'video-guid-1']);
    $this->asGuest();

    $body = trialDoor($course)->assertOk()->json('data');

    expect($body['kind'])->toBe('video')
        ->and($body['playback']['manifest_url'])->toBe("/api/v1/marketplace/courses/{$course->slug}/trial/stream")
        ->and($body['playback']['reload_after_seconds'])->toBe(400)
        ->and($body)->not->toHaveKey('embed_url');

    $response = trialDoor($course, '/stream')->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toContain('vz-trial-test.b-cdn.net')
        ->and($location)->toContain('/video-guid-1/playlist.m3u8')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    preg_match('/expires=(\d+)/', $location, $match);
    expect((int) $match[1] - now()->getTimestamp())->toBeBetween(590, 610);
});

it('records nothing against anybody: no grant row, no event', function (): void {
    [$course] = markedTrial(['type' => 'video', 'external_url' => null], asset: []);
    Event::fake([PlaybackSustained::class]);
    $this->asGuest();

    trialDoor($course)->assertOk();
    trialDoor($course, '/stream')->assertRedirect();

    expect(PlaybackGrant::query()->count())->toBe(0);
    Event::assertNotDispatched(PlaybackSustained::class);
});

it('answers a signed-in teacher from another workspace exactly as it answers a guest', function (): void {
    [$course, $lesson] = markedTrial();
    [, , $otherWorkspace] = trialFixture();

    /** @var User $stranger */
    $stranger = $otherWorkspace->owner()->firstOrFail();
    $this->setCurrentWorkspace($otherWorkspace, $stranger);
    Sanctum::actingAs($stranger);

    trialDoor($course)->assertOk()->assertJsonPath('data.uuid', (string) $lesson->uuid);
});

it('refuses with the one 404, whatever the reason', function (Closure $arrange): void {
    $course = $arrange();
    $this->asGuest();

    $show = trialDoor($course)->assertNotFound();
    $stream = trialDoor($course, '/stream')->assertNotFound();

    expect($show->json('message'))->toBe('غير متاح')
        ->and($stream->json('message'))->toBe('غير متاح');
})->with([
    'no trial marked' => [fn () => trialFixture()[0]],
    'a draft lesson' => [fn () => markedTrial(['status' => 'draft'])[0]],
    'an unpublished section' => [fn () => markedTrial(sectionStatus: 'draft')[0]],
    'a high-value lesson' => [fn () => markedTrial(['is_high_value' => true])[0]],
    'a legacy local upload' => [fn () => markedTrial(['type' => 'video', 'external_url' => null], ['provider' => 'local'])[0]],
    'an upload still processing' => [fn () => markedTrial(['type' => 'video', 'external_url' => null], ['status' => 'processing', 'ready_at' => null])[0]],
    'an article' => [fn () => markedTrial(['type' => 'article', 'external_url' => null])[0]],
    'a private course' => [function () {
        [$course] = markedTrial();
        Course::query()->withoutWorkspaceScope()->whereKey($course->id)->update(['visibility' => 'private']);

        return $course;
    }],
    'a deleted course' => [function () {
        [$course] = markedTrial();
        Course::query()->withoutWorkspaceScope()->whereKey($course->id)->firstOrFail()->delete();

        return $course;
    }],
]);

it('refuses a trial that became a session recording or a group-only lesson after marking', function (string $how): void {
    [$course, $lesson, $workspace, $teacher] = markedTrial();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($course, $lesson, $teacher, $how): void {
        if ($how === 'session') {
            $session = ClassSession::factory()->create(['workspace_id' => $lesson->workspace_id, 'teacher_profile_id' => $teacher->getKey()]);
            Lesson::query()->withoutWorkspaceScope()->findOrFail($lesson->id)->forceFill(['class_session_id' => $session->id])->save();

            return;
        }

        $cohort = Cohort::factory()->create([
            'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
            'created_by' => $course->created_by, 'name' => 'مجموعة',
        ]);
        LessonCohortScope::query()->create(['workspace_id' => $lesson->workspace_id, 'lesson_id' => $lesson->id, 'cohort_id' => $cohort->id]);
    });

    $this->asGuest();
    trialDoor($course)->assertNotFound();
})->with(['session', 'group']);

it('serves no stream for an embedded trial', function (): void {
    [$course] = markedTrial();
    $this->asGuest();

    trialDoor($course, '/stream')->assertNotFound();
});

it('limits by address in a bucket of its own, leaving the marketplace pages alone', function (): void {
    [$course] = markedTrial();
    config(['media.trial_requests_per_minute' => 3]);
    $this->asGuest();

    foreach (range(1, 3) as $_) {
        trialDoor($course)->assertOk();
    }

    trialDoor($course)->assertTooManyRequests();
    $this->getJson('/api/v1/marketplace/teachers')->assertOk();
});
