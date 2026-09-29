<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| Spec 010 · FR-005 on a LESSON'S FILE — every door of `MediaAssetPolicy`:
| the upload ticket (`create`), show and complete (`view`), the view-only switch
| (`update`), captions and deletion (`delete`).
|
| ⛔ The policy asked the permission and the workspace alone, so an assistant
| confined to one course uploaded over, re-captioned and flipped the file of a
| lesson in any other course — while `CoursePolicy` refused them the course.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST — «far is refused» alone is green against an
| assistant refused everything; «near is allowed» beside it is what makes the
| refusal mean «outside your scope».
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearLesson = $this->lessonWithVideo($this->workspace, $this->near);
    $this->farLesson = $this->lessonWithVideo($this->workspace, $this->far);
    $this->nearAsset = $this->nearLesson->mediaAsset;
    $this->farAsset = $this->farLesson->mediaAsset;

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function confineMediaAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/** The assistant role does not carry `lessons.delete`; the owner may tick it on. */
function grantMediaAssistantDeletion(): void
{
    test()->assistant->givePermissionTo(Permissions::LESSONS_DELETE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->assistant->unsetRelation('permissions');
}

/** A lesson with no file yet — a second upload onto a filled item is refused 423. */
function bareMediaLesson(Course $course): Lesson
{
    return Lesson::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'type' => 'video',
    ]);
}

function mediaTicketStatus(Lesson $lesson): int
{
    return test()->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'درس.mp4',
        'size_bytes' => 1_048_576,
        'duration_seconds' => 600,
    ])->status();
}

/** @return array<string, int> every HTTP door on the asset, by name */
function mediaDoorStatuses(MediaAsset $asset): array
{
    return [
        'show' => test()->getJson("/api/v1/media/assets/{$asset->uuid}")->status(),
        'complete' => test()->postJson("/api/v1/media/assets/{$asset->uuid}/complete")->status(),
        'disposition' => test()->putJson("/api/v1/media/assets/{$asset->uuid}/disposition", ['is_downloadable' => true])->status(),
        'captions' => test()->post(
            "/api/v1/media/assets/{$asset->uuid}/captions",
            ['file' => UploadedFile::fake()->createWithContent('l.vtt', "WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.000\nمرحباً\n")],
            ['Accept' => 'application/json'],
        )->status(),
    ];
}

it('lets a confined assistant work on their own course\'s file and refuses every other course\'s', function (): void {
    confineMediaAssistantTo($this->near);
    grantMediaAssistantDeletion();
    Sanctum::actingAs($this->assistant);

    expect(mediaTicketStatus(bareMediaLesson($this->near)))->toBe(201)
        ->and(mediaTicketStatus(bareMediaLesson($this->far)))->toBe(403);

    foreach (mediaDoorStatuses($this->nearAsset->refresh()) as $door => $status) {
        expect($status)->not->toBe(403, "near {$door}");
    }

    foreach (mediaDoorStatuses($this->farAsset->refresh()) as $door => $status) {
        expect($status)->toBe(403, "far {$door}");
    }

    // Deletion carries `2fa.required` on its route, so the verdict is read at the gate.
    expect(Gate::forUser($this->assistant)->allows('delete', $this->nearAsset))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('delete', $this->farAsset))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('update', $this->farAsset))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('view', $this->farAsset))->toBeFalse();
});

it('leaves every course\'s file to an unconfined assistant and to the owner', function (): void {
    grantMediaAssistantDeletion();

    foreach ([$this->assistant, $this->owner] as $staff) {
        Sanctum::actingAs($staff);

        expect(mediaTicketStatus(bareMediaLesson($this->far)))->toBe(201);

        foreach (mediaDoorStatuses($this->farAsset->refresh()) as $door => $status) {
            expect($status)->not->toBe(403, "far {$door}");
        }

        expect(Gate::forUser($staff)->allows('delete', $this->farAsset))->toBeTrue();
    }
});

it('refuses another workspace\'s lesson file to an assistant, confined or not', function (): void {
    [$other] = $this->createWorkspaceWithOwner();

    /** @var Lesson $foreign */
    $foreign = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other): Lesson {
        $course = Course::factory()->published()->create(['workspace_id' => $other->getKey()]);

        return $this->lessonWithVideo($other, $course);
    });
    $foreignAsset = MediaAsset::query()->withoutWorkspaceScope()
        ->where('owner_type', Lesson::class)->where('owner_id', $foreign->getKey())->firstOrFail();

    grantMediaAssistantDeletion();

    foreach ([false, true] as $confined) {
        if ($confined) {
            confineMediaAssistantTo($this->near);
        }

        Sanctum::actingAs($this->assistant->refresh());

        expect(mediaTicketStatus($foreign))->toBeIn([403, 404])
            ->and(Gate::forUser($this->assistant)->allows('view', $foreignAsset))->toBeFalse()
            ->and(Gate::forUser($this->assistant)->allows('update', $foreignAsset))->toBeFalse()
            ->and(Gate::forUser($this->assistant)->allows('delete', $foreignAsset))->toBeFalse();
    }
});

it('does not ask the scope about a file that belongs to no lesson', function (): void {
    grantMediaAssistantDeletion();

    $chatAsset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => Conversation::class,
        'owner_id' => 999_999,
        'uploaded_by_user_id' => $this->assistant->getKey(),
    ]);

    $verdicts = fn (User $user): array => [
        Gate::forUser($user)->allows('view', $chatAsset),
        Gate::forUser($user)->allows('update', $chatAsset),
        Gate::forUser($user)->allows('delete', $chatAsset),
    ];

    $unconfined = $verdicts($this->assistant);

    confineMediaAssistantTo($this->near);

    expect($verdicts($this->assistant->refresh()))->toBe($unconfined)
        // …and the confinement did take: the far lesson's file is refused now.
        ->and(Gate::forUser($this->assistant)->allows('view', $this->farAsset))->toBeFalse();
});
