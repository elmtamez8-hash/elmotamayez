<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| «درّب نفسك» — من منظورِ طالبٍ حقيقيّ.
|
| ⚠️ وكلُّ حالةٍ هنا تبني الطالبَ **بلا `addWorkspaceMember()` وبلا بذرة**، وهذا هو
| الشرطُ الذي بدونه لا يقيسُ الملفُّ شيئاً. ذانك الاثنان يختمان
| `users.last_workspace_id`، ولا شيءَ في مسارِ الطالبِ الحقيقيِّ يكتبُ ذلك العمود —
| فالسياقُ عندَه `null` دائماً، ومعه مُعرِّفُ فريقِ spatie، ومعه **كلُّ `can()`**.
|
| العيبُ الذي وجدَه هذا الملفُّ: `POST /practice/exams` كان يردُّ **٤٠٣** على كلِّ
| طالبٍ حقيقيٍّ على المنصّة، لأنّ `BuildSelfExamRequest::authorize()` سألَ
| `can(ATTEMPTS_SUBMIT)`. الصفحةُ كانت ميّتةً بالكامل، والاختباراتُ كلُّها خضراء.
*/

/** A student the product could actually have created: enrolled, member of nothing. */
function realStudentIn($workspace, Course $course): User
{
    $student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $student): void {
        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    // ⚠️ The singleton caches its resolution, so the fixture's own context would
    // otherwise be handed to the request under test — giving the "real student" a
    // workspace the product never gives them.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    expect($student->fresh()->last_workspace_id)->toBeNull();

    return $student;
}

/** A course with two tagged, answerable questions in it. */
function poolCourse($workspace, string $conceptName = 'المشتقّات'): array
{
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

    $lesson = Lesson::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
    ]);

    $concept = Concept::create([
        'workspace_id' => $workspace->getKey(),
        'name' => $conceptName,
    ]);

    foreach (['سؤال أوّل؟', 'سؤال ثانٍ؟'] as $content) {
        practiceQuestion($workspace, $content, [
            'lesson_id' => $lesson->getKey(),
            'concept_id' => $concept->getKey(),
        ]);
    }

    return [$course, $concept];
}

it('builds a paper for a student who is a member of no workspace', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    [$course] = poolCourse($workspace);
    $student = realStudentIn($workspace, $course);

    Sanctum::actingAs($student);

    /*
    | ⚠️ THE ASSERTION IS ON A PAPER, NOT ON «not 403». A status-only assertion
    | passes against a build that answers 200 with an empty attempt, which is the
    | other way this endpoint can be broken for the same person.
    */
    $response = $this->postJson('/api/v1/practice/exams', ['count' => 2, 'duration_minutes' => 10]);

    $response->assertCreated();

    expect($response->json('data.questions'))->toHaveCount(2);
    expect($response->json('data.delivered_count'))->toBe(2);
});

/*
| ⚠️ AND THE PICKERS ARE WALKED THROUGH THE REAL ENDPOINT — the shape
| `LeaderboardScopesTest` established after spec 009 offered scopes the API
| refused. A facet list that agrees only with itself is a list that can drift
| from the query it filters, and the failure is silent on both sides: an option
| that returns nothing, and a filter the page never offers.
*/
it('offers only concepts a paper can actually be built from', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    [$course, $concept] = poolCourse($workspace);

    // A second concept with no question behind it: the taxonomy has it, the pool
    // does not, and the old page would have offered it.
    $orphan = Concept::create(['workspace_id' => $workspace->getKey(), 'name' => 'فكرة بلا أسئلة']);

    $student = realStudentIn($workspace, $course);

    Sanctum::actingAs($student);

    $options = $this->getJson('/api/v1/practice/filters')->assertOk()->json('data');

    expect($options['has_questions'])->toBeTrue();

    $offered = collect($options['concepts'])->pluck('uuid')->all();

    expect($offered)->toContain((string) $concept->uuid);
    expect($offered)->not->toContain((string) $orphan->uuid);

    // Every offered option must produce a paper. This is the half that fails if
    // the facet query and the pool query ever drift apart.
    foreach ($options['concepts'] as $option) {
        $this->postJson('/api/v1/practice/exams', [
            'count' => 2,
            'duration_minutes' => 10,
            'concept_id' => $option['uuid'],
        ])->assertCreated();
    }

    foreach ($options['courses'] as $option) {
        $this->postJson('/api/v1/practice/exams', [
            'count' => 2,
            'duration_minutes' => 10,
            'course' => $option['uuid'],
        ])->assertCreated();
    }
});

/*
| ⚠️ AN EMPTY BANK IS `has_questions: false`, WHICH IS WHAT LETS THE SCREEN SAY A
| SENTENCE INSTEAD OF DRAWING A FORM THAT CAN ONLY REFUSE.
*/
it('reports an empty pool rather than offering controls that cannot narrow anything', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    $student = realStudentIn($workspace, $course);

    Sanctum::actingAs($student);

    $options = $this->getJson('/api/v1/practice/filters')->assertOk()->json('data');

    expect($options['has_questions'])->toBeFalse();
    expect($options['teachers'])->toBe([]);
    expect($options['concepts'])->toBe([]);
});

/*
| ⚠️ A COURSE UUID FROM ANOTHER TEACHER EMPTIES THE PAPER, never widens it. The
| filter is matched through the relation for the reason the assignment list's is:
| an unresolvable value must refuse rather than silently return the unfiltered
| pool, which here would be another bank's questions.
*/
it('refuses a paper filtered by a course that is not theirs', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    [$course] = poolCourse($workspace);
    $student = realStudentIn($workspace, $course);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);
    $foreign = Course::factory()->create(['workspace_id' => $other->getKey()]);

    $this->asGuest();
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/practice/exams', [
        'count' => 2,
        'duration_minutes' => 10,
        'course' => (string) $foreign->uuid,
    ])->assertStatus(422);
});
