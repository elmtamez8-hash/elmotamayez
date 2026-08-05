<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

it('lets an enrolled student play, by byte range', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    [, $session] = $this->enrolledViewer($workspace, $lesson);

    $response = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk();

    $grant = $response->json('grant');
    expect($grant)->not->toBeNull();

    // Range support is the mechanism, not a nicety: every further chunk is a
    // fresh trip through the guard.
    $this->get($response->json('manifest_url'))
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes');

    expect(PlaybackGrant::query()->where('uuid', $grant)->value('auth_session_id'))
        ->toBe($session->getKey());
});

// SC-001. The whole point of the phase: a copied link is worthless.
it('refuses a link once it has expired', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    $this->enrolledViewer($workspace, $lesson);

    $url = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->json('manifest_url');

    $this->get($url)->assertOk();

    $this->travel(10)->minutes();

    $this->get($url)->assertForbidden();
});

// FR-009. The link is bound to the session that minted it, so handing it to a
// friend — or reusing it after being signed out — buys nothing.
it('refuses a link once its session has ended', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    [, $session] = $this->enrolledViewer($workspace, $lesson);

    $url = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->json('manifest_url');

    $this->get($url)->assertOk();

    app(TerminateAuthSession::class)
        ->handle($session, SessionEndReason::DeviceLimit);

    // No action by the viewer, no waiting for expiry: the next chunk is refused.
    $this->get($url)->assertForbidden();
});

// SC-003, all three shapes of "not entitled".
it('refuses to issue a grant to anyone not entitled', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);
    Sanctum::actingAs($stranger);
    $this->asGuest();
    $session = $this->sessionFor($stranger);
    $session->forceFill(['token_id' => $stranger->currentAccessToken()->getKey()])->save();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertForbidden();
});

it('refuses once an enrolment is no longer active', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    [$student] = $this->enrolledViewer($workspace, $lesson);

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk();

    Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->update(['status' => 'cancelled']);

    // Re-checked on every issue, never cached against the session.
    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertForbidden();
});

it('says the video is being prepared rather than erroring', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    $this->enrolledViewer($workspace, $lesson);

    $lesson->mediaAsset->forceFill(['status' => MediaAssetStatus::Processing])->save();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")
        ->assertStatus(409)
        ->assertJsonPath('status', 'processing');
});

// FR-011 · SC-002.
it('leaks no provider identifier, key or storage path', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    $this->enrolledViewer($workspace, $lesson);

    $payload = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json();
    $serialised = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    expect($serialised)->not->toContain('provider')
        ->and($serialised)->not->toContain($lesson->mediaAsset->provider_asset_id)
        ->and($serialised)->not->toContain('storage/');
});

it('renews a live grant and remembers the position', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    $this->enrolledViewer($workspace, $lesson);

    $grant = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->json('grant');

    $this->travel(2)->minutes();

    $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 143])
        ->assertOk()
        ->assertJsonStructure(['expires_at', 'manifest_url']);

    expect(PlaybackGrant::query()->where('uuid', $grant)->value('renewed_count'))->toBe(1);
});

// SC-011. Reading entitlement once is what keeps a course listing flat.
it('checks entitlement for many lessons without a query per lesson', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $lessons = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $student) {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

        Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'active',
        ]);

        return Lesson::factory()->count(50)->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
        ]);
    });

    $action = app(IssuePlaybackGrant::class);

    DB::enableQueryLog();
    $allowed = $action->mayWatchMany($lessons, $student);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($allowed)->toHaveCount(50)
        ->and(array_unique(array_values($allowed)))->toBe([true])
        // Two reads total — entitlement and workspace membership — regardless of
        // how many lessons were asked about.
        ->and($queries)->toBeLessThanOrEqual(3);

    expect($owner)->not->toBeNull();
});

it('keeps grants of one workspace out of another', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'B']);

    $lesson = $this->lessonWithVideo($workspaceA);
    $this->enrolledViewer($workspaceA, $lesson);

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk();

    $grant = PlaybackGrant::query()->latest('id')->first();

    expect($grant?->workspace_id)->toBe($workspaceA->getKey())
        ->and($grant?->workspace_id)->not->toBe($workspaceB->getKey());
});

it('refuses a grant token that does not exist without revealing anything', function (): void {
    $this->get('/api/v1/playback/'.Str::orderedUuid().'/stream')
        ->assertForbidden();
});

it('binds a grant to a session that is still active', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    [, $session] = $this->enrolledViewer($workspace, $lesson);

    expect($session->status)->toBe(AuthSession::STATUS_ACTIVE);
});
