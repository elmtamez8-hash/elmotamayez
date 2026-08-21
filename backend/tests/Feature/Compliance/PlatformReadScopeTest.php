<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * The officer's queue is the PLATFORM's, and one workspace would prove nothing.
 *
 * ⚠️ `WorkspaceContext::id()` FALLS BACK TO `users.last_workspace_id` FOR EVERYBODY,
 * PLATFORM OFFICERS INCLUDED. So a platform-wide read left inside the scope returns
 * one workspace's rows and calls them the platform's — and passes its own test on a
 * single-workspace fixture, which is exactly how it shipped in the audit chain and
 * answered "nothing was bought" with a 200. Any test of a platform-wide read needs
 * TWO workspaces or it proves nothing at all.
 *
 * `data_requests` and `legal_holds` carry no tenant column, so the scope cannot bite
 * them directly — what this file measures is that nothing in the read path
 * reintroduces one, in the query or inside an eager load.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$first, $firstOwner] = $this->createWorkspaceWithOwner(['name' => 'First Academy']);
    [$second, $secondOwner] = $this->createWorkspaceWithOwner(['name' => 'Second Academy']);

    $this->firstStudent = $this->addWorkspaceMember($first, Roles::STUDENT);
    $this->secondStudent = $this->addWorkspaceMember($second, Roles::STUDENT);

    foreach ([$this->firstStudent, $this->secondStudent] as $student) {
        app(CreateDataRequest::class)->handle($student, (string) $student->uuid, DataRequestType::Erasure);
    }

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);

    /*
    | ⚠️ THE OFFICER IS GIVEN A FALLBACK WORKSPACE, WHICH IS WHAT ARMS THIS TEST.
    | With the column null the context resolves to null, `WorkspaceScope` adds no
    | condition, and a read that forgot its bypass passes anyway. Every real officer
    | has one: they open the panel from somewhere.
    */
    $this->officer->forceFill(['last_workspace_id' => $first->getKey()])->save();
});

it('shows the officer every workspace s requests, not their own', function (): void {
    Sanctum::actingAs($this->officer);

    $uuids = collect($this->getJson('/api/v1/manage/compliance/requests')->assertOk()->json())
        ->pluck('uuid')
        ->all();

    expect($uuids)->toHaveCount(2);
});

it('refuses the queue to a teacher who holds no platform permission', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/manage/compliance/requests')->assertStatus(403);
});

/*
 * ⚠️ AND EXECUTION IS THE OFFICER'S, NOT THE SUBJECT'S.
 *
 * FR-019 promises an announced execution period, which exists so that an
 * irreversible destruction is looked at by somebody who can weigh a legal hold
 * against it. A self-service execute button would make the officer endpoints
 * decorations and the notice a number in a document.
 */
it('refuses the subject their own execute button', function (): void {
    $request = DataRequest::query()->where('subject_user_id', $this->firstStudent->getKey())->sole();

    Sanctum::actingAs($this->firstStudent);

    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertStatus(403);
});

it('runs the request when the officer executes it, and records who did', function (): void {
    $request = DataRequest::query()->where('subject_user_id', $this->firstStudent->getKey())->sole();

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertOk();

    expect((int) $request->refresh()->executed_by_user_id)->toBe((int) $this->officer->getKey());
});

/*
 * ⚠️ A SECOND EXECUTE ANSWERS 409 RATHER THAN RUNNING IT AGAIN.
 *
 * Two officers pressing the button together is ordinary. The conditional UPDATE is
 * both the check and the claim, so the second one matches zero rows — and the audit
 * line names whoever actually ran it rather than whichever of them wrote last.
 */
it('lets one officer execute a request, not two', function (): void {
    $request = DataRequest::query()->where('subject_user_id', $this->firstStudent->getKey())->sole();

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertOk();
    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertStatus(409);
});

it('refuses a refusal with no reason', function (): void {
    $request = DataRequest::query()->where('subject_user_id', $this->firstStudent->getKey())->sole();

    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/refuse", ['reason' => '  '])
        ->assertStatus(422);
});

/*
 * The hold endpoints answer to their own permission, and a compliance officer holds
 * both — but a teacher holds neither. A tenant role that could place a hold could
 * freeze a student's erasure inside its own workspace and keep the data
 * indefinitely.
 */
it('keeps the hold endpoints platform-only', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/manage/compliance/holds', [
        'student_uuid' => (string) $this->firstStudent->uuid,
        'reason' => 'محاولة',
    ])->assertStatus(403);

    expect(LegalHold::query()->count())->toBe(0);

    Sanctum::actingAs($this->officer);

    $this->postJson('/api/v1/manage/compliance/holds', [
        'student_uuid' => (string) $this->firstStudent->uuid,
        'reason' => 'أمر قضائي',
    ])->assertStatus(201);

    expect(LegalHold::query()->count())->toBe(1);
});
