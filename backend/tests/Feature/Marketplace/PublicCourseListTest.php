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
| A course whose author has no public page is not listed at all.
|
| There is no standalone course page: the card's title links to the AUTHOR's
| profile, because that is where the course can be booked. And /teachers/{uuid}
| applies publiclyListed(), which a merely-existing profile does not pass. So
| listing such a course puts an entry in the marketplace whose only destination
| is a 404 — the visitor's first interaction with it is the error.
|
| That is how "Introduction to Laravel" behaved: published, public, and written
| by someone who never finished their teaching application.
*/

it('hides a course whose author has no public profile page', function (string $status): void {
    // is_publicly_listed is left true on purpose: the flag is derived, and a
    // stale one must not be able to publish the work of a teacher under review.
    $unlisted = marketplaceTeacher($this->workspace, ['approval_status' => $status]);

    marketplaceCourse($this->workspace, $unlisted, ['title' => 'كورس بلا مدرّس ظاهر']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
})->with([
    TeacherProfile::STATUS_PENDING,
    TeacherProfile::STATUS_REJECTED,
    TeacherProfile::STATUS_SUSPENDED,
]);

it('hides a course with no author at all', function (): void {
    // No code path produces this today — `created_by` is nullable in the schema
    // and nothing ever writes null to it. It is covered because the COLUMN
    // allows it and the resource therefore has a `$creator === null` branch: a
    // branch that exists and is never exercised is a branch that will be wrong
    // whenever something first reaches it. Whether the column should be nullable
    // at all is a separate question, and a migration.
    marketplaceCourse($this->workspace, null, ['title' => 'كورس بلا مؤلّف']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('lists a course whose author is approved, with its byline', function (): void {
    marketplaceCourse($this->workspace, $this->teacher, ['title' => 'كورس بمدرّس ظاهر']);

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.teacher.uuid', $this->teacher->uuid);
});

it('keeps the author condition out of the search index too', function (): void {
    // The index has no query to attach a scope to, so it stores the answer as a
    // flag. A flag that disagrees with the scope surfaces in search exactly the
    // courses the listing refuses to show.
    $unlisted = marketplaceTeacher($this->workspace, ['approval_status' => TeacherProfile::STATUS_PENDING]);

    $hidden = marketplaceCourse($this->workspace, $unlisted);
    $shown = marketplaceCourse($this->workspace, $this->teacher);

    expect($hidden->fresh()->isPubliclyListed())->toBeFalse()
        ->and($shown->fresh()->isPubliclyListed())->toBeTrue();
});
