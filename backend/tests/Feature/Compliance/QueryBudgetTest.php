<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| SC-022. The three lists this phase adds that grow without a ceiling.
|
| ⚠️ EVERY SCREEN IS MEASURED AT TWO FIXTURE SIZES, NEVER ONE. A cap of 15 on a
| three-row fixture passes at 15 for three rows and again at 15 for three hundred
| — it says the number is small, never that it is CONSTANT. That is the whole
| reason the task asks for «ضِعف حجم التثبيتة»: an N+1 hides comfortably inside a
| generous allowance until the day the allowance is the production row count.
|
| ⚠️ AND EVERY MEASUREMENT IS PRECEDED BY A WARM-UP REQUEST. spatie's permission
| cache is filled by the first authenticated request in the process, and a
| platform officer's standing is resolved through `PlatformStaffDirectory`, which
| memoises PER REQUEST — so an unwarmed first measurement carries costs the second
| does not, the bigger page looks cheaper by a fixed handful, and a per-row query
| hides inside the difference.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
});

it('lists the officer queue at a fixed cost', function (): void {
    /*
    | One erasure per FRESH student, so every row carries a different subject —
    | repeating one person would let a per-row lookup be answered from the identity
    | map and report a flat cost over a query it really does run.
    */
    $pendingRequests = function (int $count): void {
        foreach (range(1, $count) as $ignored) {
            $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
            app(CreateDataRequest::class)->handle($student, (string) $student->uuid, DataRequestType::Erasure);
        }
    };

    $pendingRequests(3);

    Sanctum::actingAs($this->officer);

    // Warm-up. Everything after this measures the page and not the sign-in.
    $this->getJson('/api/v1/manage/compliance/requests')->assertOk();

    [$small] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/compliance/requests')->assertOk(),
    );

    $pendingRequests(3);

    [$large, $response] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/compliance/requests')->assertOk(),
    );

    /*
    | ⚠️ THE EAGER LOAD ON `subject` IS THE WHOLE OF THIS ASSERTION. The queue shows
    | whose request is late, so the name is read for every row — asked per row that
    | is one `users` SELECT per line of a screen whose length is the platform's
    | backlog. Drop `->with('subject')` from `ComplianceRequestController::index`
    | and this equality is what fails.
    |
    | ⚠️ AND THE NAME IS ASSERTED PRESENT, NOT ONLY THE COUNT. `DataRequestResource`
    | wraps the subject in `whenLoaded`, so removing the eager load does not produce
    | an N+1 — it produces a queue with no names in it, silently, and a test that
    | measured queries alone would report that as an improvement. The two
    | assertions guard the two opposite mistakes: dropping the eager load, and
    | dropping the `whenLoaded` that makes it safe to have.
    */
    expect($response->json())->toHaveCount(6)
        ->and($response->json('0.subject.first_name'))->not->toBeNull()
        ->and($large)->toBe($small);
});

it('lists the breach queue at a fixed cost', function (): void {
    foreach (range(1, 3) as $index) {
        BreachReport::query()->create(['description' => "حادثٌ مبلَّغٌ عنه رقم {$index}."]);
    }

    Sanctum::actingAs($this->officer);

    $this->getJson('/api/v1/manage/compliance/breach-reports')->assertOk();

    [$small] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/compliance/breach-reports')->assertOk(),
    );

    foreach (range(4, 6) as $index) {
        BreachReport::query()->create(['description' => "حادثٌ مبلَّغٌ عنه رقم {$index}."]);
    }

    [$large, $response] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/compliance/breach-reports')->assertOk(),
    );

    /*
    | The two deadlines on each row are DERIVED from `created_at` and a
    | `platform_settings` row. `PlatformSettings::get()` read inside the Resource
    | would be one settings query per line — the shape `WithholdingReader::stamp()`
    | exists to avoid — so this equality is also the guard on that lookup staying
    | cached rather than becoming per-row.
    */
    expect($response->json())->toHaveCount(6)
        ->and($large)->toBe($small);
});

it('lists a person s own requests at a fixed cost', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    foreach ([DataRequestType::Export, DataRequestType::Access] as $type) {
        app(CreateDataRequest::class)->handle($student, (string) $student->uuid, $type);
    }

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/privacy/requests')->assertOk();

    [$small] = countingQueries(fn () => $this->getJson('/api/v1/privacy/requests')->assertOk());

    app(CreateDataRequest::class)->handle($student, (string) $student->uuid, DataRequestType::Erasure);

    [$large, $response] = countingQueries(fn () => $this->getJson('/api/v1/privacy/requests')->assertOk());

    expect($response->json())->toHaveCount(3)
        ->and($large)->toBe($small);
});
