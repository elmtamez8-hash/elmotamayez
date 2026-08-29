<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Traits\BelongsToWorkspace;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Constitution I, the half with no automatic gate.
 *
 * Every entity this spec adds is platform-owned or a bridge, which means none of
 * them carries workspace_id and no global scope touches them. A query against
 * parent_student_relations returns every family on the platform — the same total
 * exposure an unauthenticated marketplace query has, for the same reason.
 *
 * So both failure directions are tested here, because both are silent:
 *
 *   1. NO HORIZONTAL LEAK — a teacher must not read a student they do not teach.
 *      Missing this, the maths teacher reads the physics teacher's families.
 *
 *   2. ONE ENTITY, NOT ONE PER TEACHER — a student studying with three teachers
 *      has one preference, one guardian relation, one feed. Missing this is the
 *      mirror-image bug: BelongsToWorkspace applied where it does not belong,
 *      which shows up months later as duplicated people.
 */
function teacherIn(Workspace $workspace): User
{
    $teacher = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('relations-reader', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::RELATIONS_VIEW_STUDENT, 'web'));

    $teacher->assignRole($role);
    $teacher->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    return $teacher;
}

function enrol(Workspace $workspace, User $student): Enrollment
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    return Enrollment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'active',
    ]);
}

it('lets a teacher read the guardians of a student enrolled with them', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($workspace);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertOk();
});

// The constitutional guard. The permission alone must not be enough — every
// teacher holds it, so it would gate nothing.
it('refuses a teacher the guardians of a student not enrolled with them', function (): void {
    [$mine] = $this->createWorkspaceWithOwner(['name' => 'رياضيات']);
    [$theirs] = $this->createWorkspaceWithOwner(['name' => 'فيزياء']);

    $student = User::factory()->create();
    enrol($theirs, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($mine);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

it('refuses a teacher who lost the enrollment', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    $enrollment = enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($workspace);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertOk();

    $enrollment->forceFill(['status' => 'cancelled'])->save();

    // Access follows the enrollment, not a snapshot taken when it started.
    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

it('refuses a teacher reading guardians without the permission at all', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $stranger = User::factory()->create();
    $stranger->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

// Direction two: the entity must not multiply per teacher.
it('gives a student one preference set across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);
    [$c] = $this->createWorkspaceWithOwner(['name' => 'ج']);

    $student = User::factory()->create();

    foreach ([$a, $b, $c] as $workspace) {
        enrol($workspace, $student);
    }

    Sanctum::actingAs($student);

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::EnrollmentCreated->value,
            'channels' => [NotificationChannel::InApp->value],
        ]],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $student->getKey())->count())->toBe(1);
});

it('gives a student one guardian relation across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();
    enrol($a, $student);
    enrol($b, $student);

    ParentStudentRelation::factory()->parent()->create(['student_user_id' => $student->getKey()]);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/family/relations')->json())->toHaveCount(1);
});

it('gives a student one feed across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();

    Notification::factory()->create([
        'recipient_user_id' => $student->getKey(),
        'workspace_id' => $a->getKey(),
    ]);
    Notification::factory()->create([
        'recipient_user_id' => $student->getKey(),
        'workspace_id' => $b->getKey(),
    ]);

    Sanctum::actingAs($student);

    // Two notifications, one stream. Not "two per workspace", and not "only the
    // current workspace's".
    expect($this->getJson('/api/v1/notifications')->json('data'))->toHaveCount(2);
});

it('keeps none of the new tables workspace-scoped', function (): void {
    $models = [
        Notification::class,
        NotificationPreference::class,
        ParentStudentRelation::class,
        NotificationDelivery::class,
        MessageTemplate::class,
        ContactVerification::class,
    ];

    foreach ($models as $model) {
        expect(in_array(
            BelongsToWorkspace::class,
            class_uses_recursive($model),
            true,
        ))->toBeFalse("{$model} must not be workspace-scoped");
    }
});

/*
| Spec 010 · US5 — the cumulative report card, `NFR-001ب` IN BOTH DIRECTIONS.
|
| The card is one document per student per period across every teacher they study
| with. Both failure modes are silent: give it a `workspace_id` and one person
| becomes several, one per teacher, each card showing a third of their term as
| though it were the whole of it; leave the SEGMENT unscoped and a teacher's list
| carries their colleagues' grades.
*/
it('gives a student one card across every teacher', function (): void {
    [$a, $teacherA] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b, $teacherB] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();

    $card = ReportCard::factory()->published()->create(['student_user_id' => $student->getKey()]);

    foreach ([[$a, $teacherA], [$b, $teacherB]] as [$workspace, $teacher]) {
        ReportCardSegment::factory()->create([
            'report_card_id' => $card->getKey(),
            'workspace_id' => $workspace->getKey(),
            'teacher_user_id' => $teacher->getKey(),
            'student_user_id' => $student->getKey(),
        ]);
    }

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/report-cards')->assertOk()->assertJsonCount(1);

    // One card, two segments. Not two cards, and not one card showing whichever
    // workspace the reader happened to resolve to.
    $this->getJson("/api/v1/report-cards/{$card->uuid}")
        ->assertOk()
        ->assertJsonCount(2, 'segments');
});

it('refuses a teacher the card of somebody who is not their student', function (): void {
    [$a, $teacherA] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b, $teacherB] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();
    $card = ReportCard::factory()->published()->create(['student_user_id' => $student->getKey()]);

    ReportCardSegment::factory()->create([
        'report_card_id' => $card->getKey(),
        'workspace_id' => $b->getKey(),
        'teacher_user_id' => $teacherB->getKey(),
        'student_user_id' => $student->getKey(),
    ]);

    $this->setCurrentWorkspace($a, $teacherA);
    Sanctum::actingAs($teacherA);

    // ⚠️ A TEACHER IS REFUSED THE CARD ITSELF, not shown a filtered version of it.
    // `ReportCardPolicy` has no teacher branch at all: the card is the union of
    // several teachers' judgements, and a filter inside a Resource is one
    // forgotten line away from handing over a colleague's grades.
    $this->getJson("/api/v1/report-cards/{$card->uuid}")->assertForbidden();

    // And their own segment list is empty — the other teacher's row is not theirs
    // to see even in summary.
    $this->getJson('/api/v1/manage/report-card-segments')->assertOk()->assertJsonCount(0);
});

it('keeps the card platform-owned and the segment workspace-scoped', function (): void {
    // ⚠️ THE TWO ASSERTIONS ARE OPPOSITE AND BOTH ARE REQUIRED. The card must NOT
    // carry the trait — with it, one student becomes one card per teacher. The
    // segment MUST carry it — without it, `/manage/report-card-segments` would
    // depend entirely on a hand-written filter with nothing behind it.
    expect(in_array(BelongsToWorkspace::class, class_uses_recursive(ReportCard::class), true))
        ->toBeFalse('ReportCard must not be workspace-scoped');

    expect(in_array(BelongsToWorkspace::class, class_uses_recursive(ReportCardSegment::class), true))
        ->toBeTrue('ReportCardSegment is a bridge and must be workspace-scoped');
});

/*
| Spec 011 · US3 — the referral, `NFR-001أ` IN BOTH DIRECTIONS.
|
| A referral code belongs to a PERSON, not to a teacher: the student who invites
| a friend invites them to the platform, and the friend may end up studying with
| somebody else entirely. Both failure modes are silent, and this repository has
| now paid for each of them once. Leave the read unfiltered and one student's
| «من دعوتَهم» lists the whole platform's invitations; add `BelongsToWorkspace`
| and one person grows a second code per teacher, so the count on their screen
| resets every time they enrol somewhere new.
*/
it('shows a student their own referrals and no other student list', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner(['name' => 'أ']);

    $mine = User::factory()->create();
    $stranger = User::factory()->create();

    Sanctum::actingAs($mine);
    $myCode = $this->getJson('/api/v1/referrals/code')->assertOk()->json('code');

    Sanctum::actingAs($stranger);
    $theirCode = $this->getJson('/api/v1/referrals/code')->assertOk()->json('code');

    Referral::query()->create([
        'referrer_user_id' => $stranger->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
        'referral_code_id' => ReferralCode::query()->where('code', $theirCode)->sole()->getKey(),
        'status' => 'pending',
    ]);

    Sanctum::actingAs($mine);

    /*
    | ⚠️ `referrals` HAS NO WORKSPACE SCOPE AT ALL, so this list is as exposed as
    | an unauthenticated query — the explicit `where referrer_user_id` in the
    | controller is the entire guard, and nothing else would fail if it went.
    */
    expect($this->getJson('/api/v1/referrals')->assertOk()->json('data'))->toBe([])
        ->and($myCode)->not->toBe($theirCode);
});

it('gives a person ONE referral code across every teacher they study with', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();

    Sanctum::actingAs($student);

    // Read from inside one workspace and then the other. A `workspace_id` on the
    // code would mint a second one here — and the invitation already shared with
    // a friend would stop being the one the platform recognises.
    $this->setCurrentWorkspace($a, $student);
    $first = $this->getJson('/api/v1/referrals/code')->assertOk()->json('code');

    $this->setCurrentWorkspace($b, $student);
    $second = $this->getJson('/api/v1/referrals/code')->assertOk()->json('code');

    expect($second)->toBe($first)
        ->and(ReferralCode::query()->where('user_id', $student->getKey())->count())->toBe(1);
});

it('keeps the referral tables platform-owned', function (): void {
    foreach ([Referral::class, ReferralCode::class] as $model) {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
            ->toBeFalse("{$model} must not be workspace-scoped");
    }
});
