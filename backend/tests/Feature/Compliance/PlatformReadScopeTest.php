<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Actions\PlaceLegalHold;
use App\Modules\Compliance\Actions\ReleaseLegalHold;
use App\Modules\Compliance\Enums\DataRequestStatus;
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

/*
 * ⚠️ THE QUEUE NAMES THE SUBJECT, AND THE SUBJECT'S OWN LIST DOES NOT.
 *
 * An officer reading a queue of late requests has to know WHOSE is late — that is
 * the entire use of the screen, and a list of uuids and dates is unusable. The
 * relation is eager-loaded on this route alone and the resource sends it under
 * `whenLoaded`, so the key is simply absent everywhere else. Both halves are
 * asserted: an eager load quietly dropped would leave a screen of "حساب غير معروف",
 * and one added to the personal route would start sending names through a payload
 * that has no reason to carry them.
 */
it('names the subject in the officer queue and nowhere else', function (): void {
    Sanctum::actingAs($this->officer);

    $queue = $this->getJson('/api/v1/manage/compliance/requests')->assertOk()->json();

    expect($queue[0])->toHaveKey('subject')
        ->and($queue[0]['subject']['first_name'])->not->toBeNull();

    Sanctum::actingAs($this->firstStudent);

    $mine = $this->getJson('/api/v1/privacy/requests')->assertOk()->json();

    expect($mine[0])->not->toHaveKey('subject');
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

/*
 * ⚠️ AN ERASURE INTERRUPTED BY A HOLD CAN BE RUN AGAIN AFTERWARDS — AND IT COULD
 * NOT, WHICH IS THE DEAD END THIS CASE EXISTS FOR.
 *
 * Trace: the officer executes, `executed_by_user_id` is written, the walk starts, a
 * hold arrives and parks the request `on_hold`. The hold is later released and the
 * request returns to `pending`. Pressing execute again matched
 * `WHERE status = pending AND executed_by_user_id IS NULL` — already set — so it
 * answered 409 for ever, while `store` never dispatches an erasure and the sweep
 * never reads `pending`. Dead in three directions at once.
 *
 * ⚠️ AND IT WAS MASKED BY A TEST THAT BYPASSED THE ENDPOINT. `LegalHoldTest`'s
 * release case calls `dispatchSync` directly, which never meets the claim that
 * refuses. This one goes through the real route, twice, which is the only shape
 * that fails.
 */
it('lets the officer run an erasure that a hold interrupted', function (): void {
    $request = DataRequest::query()->where('subject_user_id', $this->firstStudent->getKey())->sole();

    Sanctum::actingAs($this->officer);
    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertOk();

    // The shape the job leaves when a hold arrives mid-walk.
    $hold = app(PlaceLegalHold::class)->handle($this->firstStudent, $this->officer, 'أمر وصل أثناء المحو');
    DataRequest::query()->whereKey($request->getKey())->update([
        'status' => DataRequestStatus::OnHold->value,
        'refusal_reason' => 'موقوف',
    ]);

    app(ReleaseLegalHold::class)->handle($hold, $this->officer);

    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending)
        // The spent authorisation is cleared with the reset — each RUN is answered
        // for by whoever ordered it, and a walk a court stopped was not that run.
        ->and($request->executed_by_user_id)->toBeNull();

    $this->postJson("/api/v1/manage/compliance/requests/{$request->uuid}/execute")->assertOk();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed);
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
