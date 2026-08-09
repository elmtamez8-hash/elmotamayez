<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| NFR-012 — the panel's cost does not grow with the class.
|
| ⚠️ IT COMPARES TWO SIZES AND ASSERTS THEY ARE EQUAL, never "at most fifteen".
| A fixed ceiling passes straight over an N+1 as long as the sample is small, and
| the sample in a test is always small — five students behind a per-row query is
| twenty-odd queries, which sails under any ceiling a reviewer would write.
| Equality is the only assertion that fails for the right reason.
|
| ⚠️ AND THE SIZE IS COUNTED IN BALANCE ROWS, not in students. A student enrolled
| in three courses is three rows, and seeding by student would grow the thing
| being measured at a different rate than production does.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = billingCourse($this->workspace);
});

/** Seeds `$rows` (student × course) enrolments, half of them with credits. */
function seedBalanceRows(int $rows): void
{
    $test = test();

    foreach (range(1, $rows) as $index) {
        $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
        $test->createEnrollment($test->workspace, $test->course, $student);
        $test->setCurrentWorkspace($test->workspace, $test->owner);

        if ($index % 2 === 0) {
            grantCredits(billingBalance($test->workspace, $student, $test->course), 3, "budget-{$index}");
        }
    }
}

function budgetReader(): object
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $reader = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $reader->givePermissionTo(Permissions::BILLING_BALANCE_VIEW);
    $test->setCurrentWorkspace($test->workspace, $reader);

    return $reader;
}

/** Queries spent answering the panel once. */
function panelQueryCount(): int
{
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->getJson('/api/v1/manage/billing/students')->assertOk();

    // The listener cannot be removed, so each call must be made in its own test.
    return $count;
}

it('costs the same for four rows as for forty', function (): void {
    seedBalanceRows(4);

    Sanctum::actingAs(budgetReader());

    // One unmeasured call first. The permission registrar loads this reader's
    // roles on its first check and caches them, so without a warm-up the SMALL
    // measurement carries a query the large one does not — and the test fails
    // reporting the large run as cheaper, which reads as nonsense and gets the
    // assertion loosened to a ceiling. Warming it measures the panel, not the
    // cache.
    $this->getJson('/api/v1/manage/billing/students')->assertOk();

    $small = panelQueryCount();

    seedBalanceRows(36);

    $large = panelQueryCount();

    // Not "$large is small" — $large EQUALS $small. Ten times the data, the same
    // number of round trips, or something in the path runs per row.
    expect($large)->toBe($small)
        ->and($this->getJson('/api/v1/manage/billing/students')->json('data'))->toHaveCount(40);
});
