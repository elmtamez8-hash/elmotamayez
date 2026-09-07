<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ExportArchive;

/**
 * FR-017 — the permission limits the CONTENT, not merely the door.
 *
 * ⚠️ THE DOOR AND THE CONTENT ARE TWO DIFFERENT DECISIONS, and conflating them is
 * the defect this file exists for. A guardian granted "attendance" alone opens the
 * request LEGITIMATELY once they also hold `data_rights` — the policy lets them
 * through, correctly. If the gate stopped there they would receive every mark,
 * every payment and every viewing log belonging to their child, having been
 * authorised for none of it. So each implementor of the contract asks
 * `DataSubject::mayReceive()` for its own categories, and this is what says so.
 *
 * ⚠️ AND THE SUBJECT'S OWN REQUEST IS THE CONTROL. `grantedScope` is null when a
 * person asks for their own data, and null means everything — not "authorised for
 * nothing". Without the second case here, an implementor that refused every
 * category unconditionally would pass every assertion in the first.
 */
function guardianWith(User $student, array $permissions): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => 'ابن',
        'relation_type' => RelationType::Parent->value,
        'permissions' => array_map(
            fn (GuardianPermission $permission): string => $permission->value,
            $permissions,
        ),
        'status' => RelationStatus::Active->value,
    ]);

    return $guardian;
}

function scopedExport(User $requester, User $subject): DataRequest
{
    $request = app(CreateDataRequest::class)->handle($requester, (string) $subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    return $request->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');

    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->workspace = $workspace;

    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher): void {
        $course = Course::factory()->create([
            'workspace_id' => $workspace->getKey(),
        ]);

        Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $this->student->getKey(),
            'status' => 'active',
        ]);

        $session = billableSession($workspace, $teacher, $course);

        Attendance::query()->create([
            'workspace_id' => $workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $this->student->getKey(),
            'status' => 'present',
            'source' => 'automatic',
            'stay_seconds' => 1800,
        ]);

        Order::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $this->student->getKey(),
            'course_id' => $course->getKey(),
            'kind' => OrderKind::Course,
            'amount_minor' => 22_000,
            'currency' => 'QAR',
            'provider' => 'manual',
            'status' => 'under_review',
        ]);
    });
});

it('gives an attendance-only guardian the register and nothing else', function (): void {
    $guardian = guardianWith($this->student, [
        GuardianPermission::Attendance,
        GuardianPermission::DataRights,
    ]);

    $request = scopedExport($guardian, $this->student);

    expect(ExportArchive::rows($request, 'attendance_record'))->not->toBe([])
        ->and(ExportArchive::rows($request, 'exam_attempt'))->toBe([])
        ->and(ExportArchive::rows($request, 'payment_record'))->toBe([])
        ->and(ExportArchive::rows($request, 'class_recording'))->toBe([]);
});

it('gives the subject their own record in full', function (): void {
    $request = scopedExport($this->student, $this->student);

    expect(ExportArchive::rows($request, 'attendance_record'))->not->toBe([])
        ->and(ExportArchive::rows($request, 'payment_record'))->not->toBe([])
        ->and(ExportArchive::rows($request, 'enrollment_record'))->not->toBe([]);
});

/*
 * ⚠️ `data_rights` IS THE PERMISSION, AND NOTHING ELSE IS.
 *
 * A guardian granted every permission the family knows about — attendance,
 * payments, results — still may not export the child's entire record unless they
 * were granted THIS one. It was added in 013 precisely because there was nothing
 * to be authorised for: `RecordTermsConsent` asked for `payments`, which coupled a
 * child's privacy to authority over money for no reason anybody could state.
 */
it('refuses a guardian who holds every other permission', function (): void {
    $guardian = guardianWith($this->student, [
        GuardianPermission::Attendance,
        GuardianPermission::Payments,
        GuardianPermission::Results,
        GuardianPermission::Schedule,
    ]);

    expect(fn () => app(CreateDataRequest::class)->handle(
        $guardian,
        (string) $this->student->uuid,
        DataRequestType::Export,
    ))->toThrow(DomainException::class);
});

/*
 * ⚠️ ONE REFUSAL FOR TWO DIFFERENT FACTS, AND THE MESSAGES MUST MATCH EXACTLY.
 *
 * "No such person" and "not yours" answering differently makes this endpoint an
 * ORACLE: submit a uuid and the reply confirms whether it belongs to a real
 * account. Asserting the two messages are IDENTICAL is the only form of this test
 * that fails when somebody helpfully improves one of them.
 */
it('answers identically for an unknown uuid and for somebody else s child', function (): void {
    $stranger = User::factory()->create();

    $unknown = null;
    $notYours = null;

    try {
        app(CreateDataRequest::class)->handle($stranger, (string) Str::uuid(), DataRequestType::Export);
    } catch (DomainException $exception) {
        $unknown = $exception->getMessage();
    }

    try {
        app(CreateDataRequest::class)->handle($stranger, (string) $this->student->uuid, DataRequestType::Export);
    } catch (DomainException $exception) {
        $notYours = $exception->getMessage();
    }

    expect($unknown)->not->toBeNull()
        ->and($notYours)->toBe($unknown);
});
