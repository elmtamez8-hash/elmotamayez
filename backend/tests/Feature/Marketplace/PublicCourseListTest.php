<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;

/**
 * @param  array<string, mixed>  $attrs
 */
function marketplaceCourse(Workspace $workspace, ?TeacherProfile $teacher = null, array $attrs = []): Course
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher, $attrs): Course {
        return Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher?->user_id,
            ...$attrs,
        ]);
    });
}

beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);
});

it('lists published public courses', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, ['title' => 'أساسيات الجبر']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'أساسيات الجبر')
        ->assertJsonPath('data.0.teacher.uuid', $this->teacher->uuid);
});

it('hides courses that are not published or not public', function (string $column, string $value): void {
    marketplaceCourse($this->workspace, $this->teacher, [$column => $value]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
})->with([
    ['status', 'draft'],
    ['visibility', 'private'],
]);

it('hides courses whose workspace withdrew from the marketplace', function (): void {
    marketplaceCourse($this->workspace, $this->teacher);

    $this->workspace->forceFill(['participates_in_marketplace' => false])->save();

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('merges courses across participating workspaces', function (): void {
    $other = marketplaceWorkspace('Second Academy');
    $otherTeacher = marketplaceTeacher($other);

    marketplaceCourse($this->workspace, $this->teacher);
    marketplaceCourse($other, $otherTeacher);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('filters by course type', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, ['course_type' => Course::TYPE_GROUP]);
    marketplaceCourse($this->workspace, $this->teacher, ['course_type' => Course::TYPE_RECORDED]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses?type=group')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'group');
});

it('rejects an unknown course type', function (): void {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses?type=telepathy')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

it('filters by the teaching subject of the course author', function (): void {
    $subject = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => Subject::factory()->create([
            'slug' => 'math',
            'workspace_id' => $this->workspace->getKey(),
        ]),
    );

    $this->teacher->subjects()->attach($subject);

    marketplaceCourse($this->workspace, $this->teacher);

    $other = marketplaceTeacher($this->workspace);
    marketplaceCourse($this->workspace, $other);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses?subject=math')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

// FR-053: a struck-through price must mean an actual saving.
it('reports the original price only when it is higher than the current one', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, [
        'price' => 99.99,
        'price_before_discount' => 199.99,
    ]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('data.0.price_before_discount', '199.99');
});

it('omits the original price when it does not beat the current one', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, [
        'price' => 99.99,
        'price_before_discount' => 99.99,
    ]);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('data.0.price_before_discount', null);
});

it('returns an empty page rather than a 404 when nothing matches', function (): void {
    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses?subject=nothing-here')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});

/*
| The byline is a link, so it may only name a teacher who has a page.
|
| /teachers/{uuid} applies publiclyListed(); a profile that merely exists does
| not pass it. Publishing the byline anyway is a 404 the visitor discovers by
| clicking — which is exactly how "Introduction to Laravel" behaved, its author
| having never finished their application.
*/

it('drops the byline when the author has no public profile page', function (string $status): void {
    // is_publicly_listed is left true on purpose: the flag is derived, and a
    // stale one must not be able to publish a link to a teacher under review.
    $unlisted = marketplaceTeacher($this->workspace, ['approval_status' => $status]);

    marketplaceCourse($this->workspace, $unlisted, ['title' => 'كورس بلا مدرّس ظاهر']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        // The course still lists — its own gate is its status and its workspace.
        // What it loses is the link, because there is nothing at the other end.
        ->assertJsonPath('data.0.teacher', null);
})->with([
    TeacherProfile::STATUS_PENDING,
    TeacherProfile::STATUS_REJECTED,
    TeacherProfile::STATUS_SUSPENDED,
]);

it('keeps the byline for an approved teacher', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, ['title' => 'كورس بمدرّس ظاهر']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('data.0.teacher.uuid', $this->teacher->uuid);
});
