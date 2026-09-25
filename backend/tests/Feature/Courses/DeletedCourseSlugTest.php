<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\CourseSlug;
use App\Modules\Marketplace\Models\Subject;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| A deleted course KEEPS its address.
|
| `/courses/{slug}` is one platform-wide namespace read by guests and search
| engines. Freeing a deleted course's slug would let a course in ANOTHER workspace
| take it over, and every link and search result pointing at the old course would
| start serving somebody else's — an impersonation with the first teacher's
| reputation attached. So the unique index counts soft-deleted rows, and every
| door that writes a slug has to agree with it: the two API requests, the panel
| field, and the generator `CreateCourse` falls back on. The generator was the one
| that did not — it hid deleted rows, handed their slug to a new course, and the
| insert died on the index with a raw integrity error.
*/

beforeEach(function (): void {
    [$this->workspaceA, $this->teacherA] = $this->createWorkspaceWithOwner();
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner();

    $this->deleted = app(WorkspaceContext::class)->forWorkspace(
        $this->workspaceA,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $this->workspaceA->getKey(),
            'title' => 'Algebra Basics',
            'slug' => 'algebra-basics',
        ]),
    );

    $this->deleted->delete();

    expect(Course::withTrashed()->withoutWorkspaceScope()->whereKey($this->deleted->getKey())->sole()->trashed())->toBeTrue();
});

it('does not generate a slug a deleted course still holds', function (): void {
    expect(CourseSlug::for('Algebra Basics'))->toBe('algebra-basics-2');
});

it('creates a course whose title matches a deleted one, on a new address', function (): void {
    Sanctum::actingAs($this->teacherB);

    $this->postJson('/api/v1/courses', [
        'title' => 'Algebra Basics',
        'price_minor' => 0,
        'currency' => 'QAR',
        'subject' => (string) Subject::factory()->create()->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ])->assertCreated()
        ->assertJsonPath('slug', 'algebra-basics-2');
});

it('refuses a deleted course\'s slug to a teacher in another workspace, on create and on edit', function (): void {
    Sanctum::actingAs($this->teacherB);

    $this->postJson('/api/v1/courses', [
        'title' => 'Something else',
        'slug' => 'algebra-basics',
        'price_minor' => 0,
        'currency' => 'QAR',
        'subject' => (string) Subject::factory()->create()->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ])->assertStatus(422)->assertJsonValidationErrors('slug');

    $own = Course::factory()->create(['workspace_id' => $this->workspaceB->getKey()]);

    $this->putJson("/api/v1/courses/{$own->uuid}", ['slug' => 'algebra-basics'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');

    expect(Course::query()->withoutWorkspaceScope()->where('slug', 'algebra-basics')->exists())->toBeFalse();
});

it('refuses a deleted course\'s slug from the panel instead of failing on the index', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $own = app(WorkspaceContext::class)->forWorkspace(
        $this->workspaceB,
        fn (): Course => Course::factory()->create(['workspace_id' => $this->workspaceB->getKey()]),
    );

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(EditCourse::class, ['record' => $own->getRouteKey()])
        ->fillForm(['slug' => 'algebra-basics'])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect(Course::query()->withoutWorkspaceScope()->whereKey($own->getKey())->value('slug'))->not->toBe('algebra-basics');
});
