<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Actions\PublishPeriodicReview;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| The teacher's periodic assessment of a student — `FR-028`, `FR-029`, `FR-035`.
|
| ⚠️ THE THIRD REQUIREMENT IS THE ONE WITH TEETH. A student reading another
| student's assessment is not a leak of an opinion, it is a judgement of a named
| child shown to their classmate — and the route it would arrive through is the
| student's own, where `WorkspaceScope` adds NO condition at all because a student
| is a member of no workspace. So the negative case below is not a formality: it
| measures the only guard there is, an explicit `student_user_id` filter.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    $this->classmate = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->classmate);
});

/** The body the teacher's screen sends. */
function reviewPayload(User $student, array $overrides = []): array
{
    return array_merge([
        'student_uuid' => $student->uuid,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'commitment' => 5,
        'participation' => 4,
        'homework' => 3,
        'improvement' => 4,
        'note' => 'تحسّن ملحوظ في الواجبات.',
    ], $overrides);
}

it('lets the teacher write and publish an assessment', function (): void {
    Sanctum::actingAs($this->teacher);

    $created = $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated();

    $uuid = (string) $created->json('uuid');

    expect($created->json('is_published'))->toBeFalse()
        // Derived on the server and nowhere else: (5+4+3+4)/4.
        ->and((float) $created->json('average'))->toBe(4.0);

    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")
        ->assertOk()
        ->assertJsonPath('is_published', true);
});

/*
| ⚠️ A DRAFT IS NOT VISIBLE TO ITS SUBJECT, and this is what makes the conditional
| claim in `PublishPeriodicReview` worth having. Leaked before publication, the
| student reads a half-written judgement of themselves and the «tell them once»
| design guards a door that was already open.
*/
it('hides a draft from the student and shows it once published', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = (string) $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated()->json('uuid');

    Sanctum::actingAs($this->student);
    $this->getJson('/api/v1/students/me/reviews')->assertOk()->assertJsonCount(0);

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->assertOk();

    Sanctum::actingAs($this->student);

    // ⚠️ `0.uuid`, NOT `data.0.uuid`. `JsonResource::withoutWrapping()` is enabled,
    // so a collection response is a BARE array — written the other way this reads
    // null and reports «expected iterable», a puzzle about Pest and not about the
    // product.
    $this->getJson('/api/v1/students/me/reviews')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.uuid', $uuid)
        ->assertJsonPath('0.commitment', 5)
        /*
        | ⚠️ THE TEACHER'S NAME, ASSERTED AS A VALUE AND NOT AS A KEY. It arrives
        | through `whenLoaded('teacher')`, and the eager load selected `id,name` —
        | but `users` HAS NO `name` COLUMN, it is an accessor over `first_name` and
        | `last_name`. So the relation loaded, the key was present, and the value
        | was an empty string on every screen. Found against the running app, not
        | here: the first version of this test asserted the axes and never the name.
        */
        ->assertJsonPath('0.teacher_name', $this->teacher->name);
});

it('shows a published assessment to an authorised guardian and to nobody else', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = (string) $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->assertOk();

    $guardian = guardianOf($this->student, [GuardianPermission::Results]);
    $bystander = guardianOf($this->student, [GuardianPermission::Attendance]);

    Sanctum::actingAs($guardian);
    $this->getJson('/api/v1/students/me/reviews?student='.$this->student->uuid)
        ->assertOk()
        ->assertJsonCount(1);

    /*
    | ⚠️ THE PERMISSION IS THE POINT, NOT MERELY THE RELATION. A guardian entitled
    | to attendance news and nothing else has no business reading a judgement of
    | their child's homework — and 403 rather than an empty list, because an empty
    | list would say «no assessments exist» about a child who has one.
    */
    Sanctum::actingAs($bystander);
    $this->getJson('/api/v1/students/me/reviews?student='.$this->student->uuid)
        ->assertForbidden();
});

it('never shows one student the assessment of another', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = (string) $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->assertOk();

    Sanctum::actingAs($this->classmate);

    // Their own list is empty, and asking about the other student by uuid is a
    // 403 rather than a list — the classmate is nobody's guardian.
    $this->getJson('/api/v1/students/me/reviews')->assertOk()->assertJsonCount(0);
    $this->getJson('/api/v1/students/me/reviews?student='.$this->student->uuid)->assertForbidden();
});

/*
| ⚠️ A BARE UUID IS AN IDENTITY PROBE (`NFR-001أ`). Without the enrolment check,
| any user's uuid names a student this teacher may assess — and the row would come
| back carrying their name. `exists:users,uuid` answers a different question, which
| is exactly why it is not in the FormRequest.
*/
it('refuses a student who is not enrolled with this teacher', function (): void {
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($stranger))
        ->assertStatus(422);

    expect(PeriodicReview::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('revises a draft rather than writing a second row for the period', function (): void {
    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))->assertCreated();

    $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student, ['commitment' => 2]))
        ->assertOk()
        ->assertJsonPath('commitment', 2);

    expect(PeriodicReview::query()->withoutGlobalScopes()->count())->toBe(1);
});

/*
| ⚠️ PUBLISHING TWICE MUST NOTIFY ONCE, AND A SINGLE-THREADED TEST STILL REACHES
| IT. The claim is a conditional UPDATE; written as a read followed by a write, two
| taps on a slow connection both see `published_at` as null, both write, and the
| guardian is billed for two WhatsApp messages about one assessment.
*/
it('notifies the student exactly once however often publish is called', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = (string) $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->assertOk();
    $this->postJson("/api/v1/manage/periodic-reviews/{$uuid}/publish")->assertOk();

    $notifications = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::PeriodicReviewPublished->value)
        ->count();

    expect($notifications)->toBe(1);
});

// And at the Action, which the seeders and Filament reach with no request behind
// them (Constitution II).
it('refuses to rewrite a published assessment', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = (string) $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student))
        ->assertCreated()->json('uuid');

    $review = PeriodicReview::query()->withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
    app(PublishPeriodicReview::class)->handle($review);

    $this->postJson('/api/v1/manage/periodic-reviews', reviewPayload($this->student, ['commitment' => 1]))
        ->assertStatus(422);

    expect($review->fresh()?->commitment)->toBe(5);
});
