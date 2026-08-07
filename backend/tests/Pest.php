<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WithWorkspace;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

uses(WithWorkspace::class)->in('Feature');

/*
 * Message templates are reference data, not fixtures.
 *
 * A dispatch with no template for its type renders nothing and is dropped with a
 * logged error (FR-037), so without this every notification assertion in the
 * suite would pass vacuously — asserting zero and getting zero. Seeded here
 * rather than per-test for the same reason roles are: it is a precondition of
 * the app running at all, not of any one scenario.
 */
uses()->beforeEach(function (): void {
    $this->seed(NotificationTemplateSeeder::class);
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Marketplace helpers
|--------------------------------------------------------------------------
|
| Shared by every Feature/Marketplace test. They live here rather than in one
| test file because a global function declared inside a test file is only
| available to the files Pest happens to load after it.
|
*/

function marketplaceWorkspace(string $name = 'Academy', bool $participates = true): Workspace
{
    /** @var Workspace $workspace */
    [$workspace] = test()->createWorkspaceWithOwner(['name' => $name]);

    $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

    return $workspace;
}

/** @param array<string, mixed> $attrs */
function marketplaceTeacher(Workspace $workspace, array $attrs = []): TeacherProfile
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn () => TeacherProfile::factory()
            ->published()
            ->scored()
            ->create([...$attrs, 'workspace_id' => $workspace->getKey()]),
    );
}

/**
 * Post a review as a marketplace student.
 *
 * The asGuest() call is not cosmetic: WorkspaceContext freezes on its first
 * resolution, the fixtures resolved it to the academy, and a student with no
 * workspace would otherwise hand spatie a stale team id.
 */
function postReview(User $student, string $teacherUuid, int $rating = 5, ?string $comment = 'ممتاز'): TestResponse
{
    Sanctum::actingAs($student);
    test()->asGuest();

    return test()->postJson("/api/v1/teachers/{$teacherUuid}/reviews", array_filter([
        'rating' => $rating,
        'comment' => $comment,
    ], fn ($value) => $value !== null));
}

/**
 * A marketplace student who has finished a course this teacher created — the
 * evidence SubmitReview demands (FR-018). The student belongs to no workspace,
 * which is exactly the shape of a real marketplace signup.
 */
function studentWhoCompletedWith(TeacherProfile $teacher): User
{
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    /** @var Workspace $workspace */
    $workspace = $teacher->workspace;

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($teacher, $student, $workspace): void {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
        ]);

        Enrollment::factory()->completed()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
        ]);
    });

    return $student;
}

/**
 * Every string key in a nested payload, flattened.
 *
 * Recursive on purpose: a field smuggled three levels down is still on the wire,
 * and a check on the top level only would pass while the leak sat inside
 * `period` or `deductions`.
 *
 * Here rather than beside its first caller because two suites now walk payloads
 * this way — StatementPayloadTest against the teacher's, ContextIsolationTest
 * against the student's — and a helper declared in a test file only exists once
 * that particular file has been loaded.
 *
 * @return list<string>
 */
function settlementPayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...settlementPayloadKeys($child)];
    }

    return $keys;
}
