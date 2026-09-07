<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Policies\DataRequestPolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

/**
 * NFR-001أ — a teacher may not open a rights request about their own student.
 *
 * ⚠️ `RELATIONS_VIEW_STUDENT` OPENS NO SUCH REQUEST, and the reason is what makes
 * this a guard rather than a preference. That permission is held by every teacher
 * and every assistant in the product; if it authorised a data request it would make
 * a CROSS-WORKSPACE export of a child's entire record — every other teacher they
 * study with, every payment, every mark — a routine staff capability, reachable
 * from a screen that says "view my student".
 *
 * ⚠️ AND AN ACTIVE ENROLMENT IS THE HARDEST CASE, WHICH IS WHY IT IS THE FIXTURE.
 * The teacher here demonstrably has a legitimate relationship with this student:
 * they teach them, they are paid for it, and the platform lets them read the
 * student's marks and attendance inside their own workspace. None of that is
 * authority over the person's data as a whole.
 */
beforeEach(function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();

    $this->workspace = $workspace;
    $this->teacher = $teacher;
    $this->student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    $this->createEnrollment($workspace, courseWithRate((int) $workspace->getKey()), $this->student);
});

it('refuses a teacher a rights request about their own active student', function (): void {
    expect(fn () => app(CreateDataRequest::class)->handle(
        $this->teacher,
        (string) $this->student->uuid,
        DataRequestType::Export,
    ))->toThrow(DomainException::class);
});

it('refuses over the endpoint too', function (): void {
    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/privacy/requests', [
        'student_uuid' => (string) $this->student->uuid,
        'type' => DataRequestType::Export->value,
    ])->assertStatus(422);

    expect(DataRequest::query()->count())->toBe(0);
});

/*
 * ⚠️ THE POLICY IS REGISTERED, ASSERTED BY NAME.
 *
 * Laravel's policy guesser fails OPEN: no policy found means "no policy applies",
 * and every refusal assertion above would still pass against a `Gate::policy()`
 * line that was never added — the Action refuses on its own. That is exactly how
 * `taxonomy.manage` shipped declared, seeded, tested and guarding nothing.
 */
it('registers the policy for the model', function (): void {
    expect(Gate::getPolicyFor(DataRequest::class))->toBeInstanceOf(DataRequestPolicy::class);
});

/*
 * And the officer's permission is PLATFORM-level: no tenant role holds it, so a
 * workspace owner cannot grant it to themselves from `/admin`.
 */
it('keeps the officer permission out of every tenant role', function (): void {
    expect($this->teacher->can(Permissions::COMPLIANCE_REQUESTS_EXECUTE))->toBeFalse();
});

/*
 * ⚠️ AND THE OFFICER MAY READ THE RECORD WITHOUT READING THE ARCHIVE.
 *
 * `compliance.requests.execute` is the authority to RUN a request and to see that
 * it ran. Letting it also open the file would make "everything the platform knows
 * about any child" a standing entitlement of whoever is on the compliance rota.
 */
it('lets an officer see the request but not download it', function (): void {
    /*
    | The platform roles are REFERENCE DATA, not fixtures: `Gate::before` turns a
    | `platform_staff` row into the permissions of the teamless spatie role, so
    | without the seeder the officer holds an empty set and every assertion below
    | passes by finding nothing. `tests/Pest.php` seeds the catalogue and the
    | templates for the same reason; the roles are seeded per test because ~1,600
    | of them would otherwise pay for a table two files read.
    */
    $this->seed(RolesAndPermissionsSeeder::class);

    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
    $request = app(CreateDataRequest::class)->handle(
        $this->student,
        (string) $this->student->uuid,
        DataRequestType::Export,
    );

    expect($officer->can('view', $request))->toBeTrue()
        ->and($officer->can('download', $request))->toBeFalse();
});
