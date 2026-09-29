<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on exams. A confined assistant holding every exam
| permission opens, edits, fills, publishes and deletes the exams of THEIR
| courses — and nothing else, even given a uuid.
|
| Until this file, `ExamPolicy` never asked the scope (`AttemptPolicy` and
| `GradingPolicy` did): every exam door answered 2xx to anyone in the workspace
| who held the permission and the uuid, the manage list showed every paper in
| the workspace, and an exam could be created for — or moved to — a far course
| or none.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST: «far is refused» alone is green against an
| assistant who is refused everything.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->nearCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->createEnrollment($this->workspace, $this->nearCourse, $this->student);
    $this->createEnrollment($this->workspace, $this->farCourse, $this->student);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo([
        Permissions::EXAMS_VIEW,
        Permissions::EXAMS_CREATE,
        Permissions::EXAMS_UPDATE,
        Permissions::EXAMS_DELETE,
        Permissions::EXAMS_PUBLISH,
        Permissions::QUESTIONS_MANAGE,
    ]);

    $this->assistantRow = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

/** A paper with one bank question in it; `null` course = set for the workspace at large. */
function exPaper(?Course $course, bool $published = false): Exam
{
    $workspace = test()->workspace;

    $exam = Exam::factory()
        ->when($published, fn ($factory) => $factory->published())
        ->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course?->getKey(),
        ]);

    bankQuestion($workspace, $exam);

    return $exam;
}

function exConfineTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assistantRow->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/**
 * Every staff door on one exam, as the status each answered. Delete runs last,
 * because an allowed delete removes the paper the others asked about.
 *
 * @return array<string, int>
 */
function exDoors(Exam $exam, bool $withDelete = true): array
{
    $test = test();
    $question = bankQuestion($test->workspace);

    $doors = [
        'show' => $test->getJson("/api/v1/exams/{$exam->uuid}")->getStatusCode(),
        'update' => $test->putJson("/api/v1/exams/{$exam->uuid}", ['title' => 'عنوان آخر'])->getStatusCode(),
        'items' => $test->getJson("/api/v1/manage/exams/{$exam->uuid}/items")->getStatusCode(),
        'sync' => $test->putJson("/api/v1/manage/exams/{$exam->uuid}/items", ['items' => [['uuid' => $question->uuid]]])->getStatusCode(),
        'publish' => $test->postJson("/api/v1/exams/{$exam->uuid}/publish")->getStatusCode(),
    ];

    if ($withDelete) {
        $doors['delete'] = $test->deleteJson("/api/v1/exams/{$exam->uuid}")->getStatusCode();
    }

    return $doors;
}

/** @return array<string, int> */
function exAllowed(): array
{
    return ['show' => 200, 'update' => 200, 'items' => 200, 'sync' => 200, 'publish' => 200, 'delete' => 204];
}

/** @return array<string, int> */
function exRefused(): array
{
    return ['show' => 403, 'update' => 403, 'items' => 403, 'sync' => 403, 'publish' => 403, 'delete' => 403];
}

/** @return list<string> */
function exIds(Exam ...$exams): array
{
    return array_map(fn (Exam $exam): string => (string) $exam->uuid, $exams);
}

/** @return list<string> */
function exListed(): array
{
    return collect(test()->getJson('/api/v1/exams')->assertOk()->json('data'))->pluck('uuid')->all();
}

it('opens every staff door on a confined assistant\'s own course and refuses every one outside it', function (): void {
    exConfineTo($this->nearCourse);
    $near = exPaper($this->nearCourse);
    $far = exPaper($this->farCourse);

    Sanctum::actingAs($this->assistant);

    expect(exDoors($far))->toBe(exRefused());

    // Nothing was written through the refused doors.
    $stored = Exam::query()->whereKey($far->getKey())->sole();
    expect($stored->title)->not->toBe('عنوان آخر')
        ->and($stored->status)->toBe('draft')
        ->and($stored->items()->count())->toBe(1);

    expect(exDoors($near))->toBe(exAllowed());
});

it('refuses a confined assistant every door on an exam set for no course', function (): void {
    exConfineTo($this->nearCourse);
    $loose = exPaper(null);

    Sanctum::actingAs($this->assistant);

    expect(exDoors($loose))->toBe(exRefused());
    expect(Exam::query()->whereKey($loose->getKey())->exists())->toBeTrue();
});

it('refuses a confined assistant the far course\'s PUBLISHED exam too, which the student branch used to open', function (): void {
    exConfineTo($this->nearCourse);
    $far = exPaper($this->farCourse, published: true);
    $loose = exPaper(null, published: true);

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/exams/{$far->uuid}")->assertForbidden();
    $this->getJson("/api/v1/exams/{$loose->uuid}")->assertForbidden();
    $this->postJson("/api/v1/exams/{$far->uuid}/attempts")->assertForbidden();
});

it('opens every door, near, far and course-less, to an unconfined assistant and to the owner', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        foreach ([$this->nearCourse, $this->farCourse, null] as $course) {
            expect(exDoors(exPaper($course)))->toBe(exAllowed());
        }
    }
});

it('lists to a confined assistant only the exams of their own courses', function (): void {
    exConfineTo($this->nearCourse);
    $near = exPaper($this->nearCourse);
    $nearPublished = exPaper($this->nearCourse, published: true);
    $far = exPaper($this->farCourse);
    $farPublished = exPaper($this->farCourse, published: true);
    $loose = exPaper(null, published: true);

    Sanctum::actingAs($this->assistant);

    expect(exListed())->toEqualCanonicalizing(exIds($near, $nearPublished));

    // And the course filter on top of it cannot widen it back.
    expect(collect($this->getJson("/api/v1/exams?course={$this->farCourse->uuid}")->json('data'))->pluck('uuid')->all())
        ->toBe([]);

    foreach ([$this->owner] as $reader) {
        Sanctum::actingAs($reader);

        expect(exListed())->toEqualCanonicalizing(exIds($near, $nearPublished, $far, $farPublished, $loose));
    }
});

it('lists every exam of the workspace to an unconfined assistant', function (): void {
    $papers = [exPaper($this->nearCourse), exPaper($this->farCourse, published: true), exPaper(null)];

    Sanctum::actingAs($this->assistant);

    expect(exListed())->toEqualCanonicalizing(exIds(...$papers));
});

it('lets a confined assistant create an exam only for a course inside the scope', function (): void {
    exConfineTo($this->nearCourse);
    Sanctum::actingAs($this->assistant);

    $before = Exam::query()->count();

    $this->postJson('/api/v1/exams', ['title' => 'بعيد', 'course_id' => $this->farCourse->getKey()])
        ->assertForbidden();
    $this->postJson('/api/v1/exams', ['title' => 'لكل الطلاب'])
        ->assertForbidden();
    $this->postJson('/api/v1/exams', ['title' => 'لكل الطلاب', 'course_id' => null])
        ->assertForbidden();

    expect(Exam::query()->count())->toBe($before);

    $this->postJson('/api/v1/exams', ['title' => 'قريب', 'course_id' => $this->nearCourse->getKey()])
        ->assertCreated();
});

it('lets the owner and an unconfined assistant create an exam for any course or none', function (): void {
    foreach ([$this->assistant, $this->owner] as $author) {
        Sanctum::actingAs($author);

        $this->postJson('/api/v1/exams', ['title' => 'بعيد', 'course_id' => $this->farCourse->getKey()])
            ->assertCreated();
        $this->postJson('/api/v1/exams', ['title' => 'لكل الطلاب'])
            ->assertCreated();
    }
});

it('refuses a confined assistant moving their own exam out of the scope, and leaves an edit that names no course alone', function (): void {
    exConfineTo($this->nearCourse);
    $near = exPaper($this->nearCourse);

    Sanctum::actingAs($this->assistant);

    $this->putJson("/api/v1/exams/{$near->uuid}", ['title' => 'منقول', 'course_id' => $this->farCourse->getKey()])
        ->assertForbidden();
    $this->putJson("/api/v1/exams/{$near->uuid}", ['title' => 'منقول', 'course_id' => null])
        ->assertForbidden();

    expect(Exam::query()->whereKey($near->getKey())->value('course_id'))->toBe($this->nearCourse->getKey());

    // ⚠️ An ABSENT course is «leave it where it is», not «move it to none».
    $this->putJson("/api/v1/exams/{$near->uuid}", ['title' => 'عنوان فقط'])->assertOk();

    expect(Exam::query()->whereKey($near->getKey())->sole())
        ->title->toBe('عنوان فقط')
        ->course_id->toBe($this->nearCourse->getKey());
});

it('never opens another workspace\'s exam to the owner or the assistant', function (): void {
    [$other] = $this->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other): Exam {
        $course = Course::factory()->create(['workspace_id' => $other->getKey()]);

        return Exam::factory()->create(['workspace_id' => $other->getKey(), 'course_id' => $course->getKey()]);
    });

    foreach ([$this->owner, $this->assistant] as $reader) {
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/exams/{$foreign->uuid}")->assertForbidden();
        $this->putJson("/api/v1/exams/{$foreign->uuid}", ['title' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/manage/exams/{$foreign->uuid}/items")->assertNotFound();
        $this->postJson("/api/v1/exams/{$foreign->uuid}/publish")->assertNotFound();
        $this->deleteJson("/api/v1/exams/{$foreign->uuid}")->assertNotFound();

        expect(exListed())->not->toContain((string) $foreign->uuid);
    }
});

it('leaves the student opening and sitting the exams of every course they study', function (): void {
    exConfineTo($this->nearCourse);
    $far = exPaper($this->farCourse, published: true);
    $loose = exPaper(null, published: true);

    Sanctum::actingAs(User::query()->findOrFail($this->student->getKey()));

    $this->getJson("/api/v1/exams/{$far->uuid}")->assertOk();
    $this->getJson("/api/v1/exams/{$loose->uuid}")->assertOk();
    $this->postJson("/api/v1/exams/{$far->uuid}/attempts")->assertSuccessful();

    expect(exListed())->toContain((string) $far->uuid, (string) $loose->uuid);
});

/*
| ⚠️ A SCOPE REFUSAL FALLS THROUGH TO THE STUDENT'S OWN ENTITLEMENT (the chat
| rule, and #298's). A confined assistant ALSO actively enrolled in the far
| course opens and sits its published exam as a student — and gains nothing a
| student lacks.
*/
it('lets a confined assistant enrolled in the far course sit its exam, and nothing more', function (): void {
    exConfineTo($this->nearCourse);
    $far = exPaper($this->farCourse, published: true);
    $farDraft = exPaper($this->farCourse);
    $loose = exPaper(null, published: true);

    Enrollment::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->farCourse->getKey(),
        'student_user_id' => $this->assistant->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/exams/{$far->uuid}")->assertOk();
    $this->postJson("/api/v1/exams/{$far->uuid}/attempts")->assertSuccessful();

    // The course's exam tab still lists it — the published paper only.
    expect(collect($this->getJson("/api/v1/exams?course={$this->farCourse->uuid}")->json('data'))->pluck('uuid')->all())
        ->toBe(exIds($far));

    // Every staff power on the far exam stays refused.
    $staff = exDoors($far);
    unset($staff['show']);
    $refused = exRefused();
    unset($refused['show']);

    expect($staff)->toBe($refused);

    // A draft in the far course is not a student's to read, nor is the course-less paper.
    $this->getJson("/api/v1/exams/{$farDraft->uuid}")->assertForbidden();
    $this->getJson("/api/v1/exams/{$loose->uuid}")->assertForbidden();
});
