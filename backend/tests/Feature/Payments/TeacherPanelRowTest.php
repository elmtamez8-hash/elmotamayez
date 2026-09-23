<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Support\StudentBalanceAllowlist;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-020 · FR-054 · FR-055 — the panel, tested at the level of ROWS.
|
| A field list is a guard on columns; FR-054 and FR-055 are rules about which
| ROWS exist at all, and a sweep over a Resource cannot see them — it never
| learns which records were selected. So both are asserted here directly:
|
|   · a student enrolled with one teacher does not appear in another's panel;
|   · and access follows the enrolment, not a snapshot taken when it began —
|     ending it removes the row on the NEXT request, with nothing to re-run.
|
| Precedent: PlatformOwnershipTest, which makes the same distinction about a
| teacher reading a student's guardians.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الرياضيات']);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = billingCourse($this->workspace);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->enrollment = $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 5, 'panel');
});

/** A reader who holds the teacher-side balance permission in this workspace. */
function panelReader(object $workspace): object
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $reader = $test->addWorkspaceMember($workspace, Roles::TENANT_OWNER);
    $reader->givePermissionTo(Permissions::BILLING_BALANCE_VIEW);
    $test->setCurrentWorkspace($workspace, $reader);

    return $reader;
}

it('shows the teacher their own students and nobody else', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    $otherCourse = billingCourse($other);
    $otherStudent = $this->addWorkspaceMember($other, Roles::STUDENT);
    $this->createEnrollment($other, $otherCourse, $otherStudent);

    grantCredits(billingBalance($other, $otherStudent, $otherCourse), 9, 'other');

    Sanctum::actingAs(panelReader($this->workspace));

    $rows = $this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['student_uuid'])->toBe($this->student->uuid)
        ->and($rows[0]['remaining_credits'])->toBe(5);
});

it('drops the row on the next request once the enrolment ends', function (): void {
    Sanctum::actingAs(panelReader($this->workspace));

    expect($this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data'))->toHaveCount(1);

    // Access follows the enrolment, not a snapshot taken when it started. There
    // is nothing to re-run and nothing to clean up — the next read simply asks
    // again.
    Enrollment::query()->whereKey($this->enrollment->getKey())->update(['status' => 'cancelled']);

    expect($this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data'))->toBe([]);
});

it('shows a student who has never bought anything, as zeros', function (): void {
    // The account is created lazily, so this student has no balance row at all —
    // and they are exactly the row a teacher needs to see. Driving the panel from
    // `credit_balances` would leave them out, and a panel that fills up as people
    // pay reads as "everyone is fine" on the first day of a class.
    $newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $newcomer);

    Sanctum::actingAs(panelReader($this->workspace));

    $rows = collect($this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data'));
    $row = $rows->firstWhere('student_uuid', $newcomer->uuid);

    expect($row)->not->toBeNull()
        ->and($row['remaining_credits'])->toBe(0)
        // NOT withheld, and the panel must say exactly what the booking gate
        // says. Withholding is a statement about a balance that ran out, not
        // about a row that was never created — and a panel showing "موقوف" for a
        // student who can book perfectly well is a teacher chasing a payment
        // nobody owes.
        ->and($row['is_withheld'])->toBeFalse();
});

it('sends the teacher no money, only credits', function (): void {
    Sanctum::actingAs(panelReader($this->workspace));

    $rows = $this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data');

    // FR-021ج — the total is (teacher rate + platform constants) × credits, so a
    // teacher holding two priced rows solves for the platform's margin.
    $unlisted = array_values(array_diff(array_keys($rows[0]), StudentBalanceAllowlist::fields()));
    $forbidden = array_values(array_intersect(array_keys($rows[0]), StudentBalanceAllowlist::forbidden()));

    expect($unlisted)->toBe([])
        ->and($forbidden)->toBe([]);
});

it('refuses a reader without the balance permission', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $member);

    Sanctum::actingAs($member);

    $this->getJson('/api/v1/manage/billing/students')->assertForbidden();
});

it('survives an enrolment whose student no longer exists', function (): void {
    /*
    | ⚠️ MEASURED ON A REAL DATABASE (029 · T057), NOT IMAGINED. The teacher's
    | dashboard card answered 500 and the whole panel with it:
    | `ErrorException: Attempt to read property "uuid" on null`, from ONE row
    | out of six.
    |
    | It is reachable because `enrollments.student_user_id` carries NO foreign
    | key — a bare `unsignedBigInteger` with an index, on MySQL as much as on
    | SQLite — so nothing deletes the enrolment when the user goes. And no
    | fixture in the suite could show it: every one of them builds the enrolment
    | from a student it created a line earlier.
    |
    | The assertion is that the OTHER rows survive. A test that only asserted
    | «200» would pass over an implementation that returns an empty list.
    */
    $ghost = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $ghost);

    DB::table('users')->where('id', $ghost->getKey())->delete();

    Sanctum::actingAs(panelReader($this->workspace));

    $rows = collect($this->getJson('/api/v1/manage/billing/students')->assertOk()->json('data'));

    expect($rows->firstWhere('student_uuid', $this->student->uuid))->not->toBeNull()
        ->and($rows->pluck('student_uuid'))->not->toContain($ghost->uuid);
});

it('pages the panel and carries the envelope a reader needs to find page two', function (): void {
    // The list used to be the whole workspace in one response. Paginated, the
    // `meta` is what tells the screen there is more — without it the table stops
    // at the first page and says nothing.
    foreach (range(1, 2) as $ignored) {
        $extra = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $extra);
    }

    Sanctum::actingAs(panelReader($this->workspace));

    $first = $this->getJson('/api/v1/manage/billing/students?per_page=2')->assertOk();
    $second = $this->getJson('/api/v1/manage/billing/students?per_page=2&page=2')->assertOk();

    $uuids = collect($first->json('data'))->pluck('student_uuid')
        ->merge(collect($second->json('data'))->pluck('student_uuid'));

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.total'))->toBe(3)
        ->and($first->json('meta.last_page'))->toBe(2)
        ->and($first->json('meta.current_page'))->toBe(1)
        ->and($second->json('data'))->toHaveCount(1)
        // Every enrolment on exactly one page: a stable order, no row twice.
        ->and($uuids->unique())->toHaveCount(3);
});

it('keeps a page full when an orphaned enrolment sits among the rows', function (): void {
    // Filtered only after the page is cut, an orphan would make its page come
    // back short and the total would count a person listed on no page at all.
    $ghost = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $ghost);
    $other = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $other);

    DB::table('users')->where('id', $ghost->getKey())->delete();

    Sanctum::actingAs(panelReader($this->workspace));

    $response = $this->getJson('/api/v1/manage/billing/students?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(2);
});

it('counts withheld PEOPLE over every page, not the rows of one', function (): void {
    /*
    | The dashboard card reads this number. Before pagination it counted the
    | rows it was handed; after, that would be the first page only — quietly
    | wrong for exactly the teachers with the most students.
    |
    | One student withheld in TWO courses is one person; a second student, on
    | another page, is withheld too. Expected: 2, whatever the page size.
    */
    $second = billingCourse($this->workspace);
    $this->createEnrollment($this->workspace, $second, $this->student);

    $late = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $late);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    billingBalance($this->workspace, $this->student, $this->course)
        ->forceFill(['remaining_credits' => -2])->save();
    billingBalance($this->workspace, $this->student, $second)
        ->forceFill(['remaining_credits' => -1])->save();
    billingBalance($this->workspace, $late, $this->course)
        ->forceFill(['remaining_credits' => -3])->save();

    Sanctum::actingAs(panelReader($this->workspace));

    $response = $this->getJson('/api/v1/manage/billing/students?per_page=1')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.withheld_students'))->toBe(2);
});
