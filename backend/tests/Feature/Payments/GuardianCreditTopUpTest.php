<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\LinkGuardian;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
|------------------------------------------------------------------------------
| Spec 031 — A GUARDIAN TOPS UP THEIR CHILD'S CREDITS.
|------------------------------------------------------------------------------
|
| ⚠️ **NOT ONE RELATION HERE IS WRITTEN `active` BY HAND.** Every link is created
| by `LinkGuardian` and activated by the child through the real 030 route, which
| is the whole reason 030 had to ship first: before it, a relation naming an
| account was born `pending` and nothing in `app/` could move it — so a fixture
| that stamped `active` was proving a feature over data the platform could not
| produce. The cost of doing it properly is four lines per test; the cost of not
| doing it was a spec that shipped dead behind a green suite.
|
| ⚠️ **AND NEITHER `last_workspace_id` IS EVER STAMPED.** Both the child and the
| guardian are members of no workspace in production, so `WorkspaceContext::id()`
| is null for them — a fixture that stamps it measures a person who does not
| exist, and every scope assertion below would be about them rather than about
| the code.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->elsewhere] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الفيزياء']);

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->course->forceFill(['title' => 'الفيزياء ٣'])->save();

    /*
    | A second course at the SAME teacher, and one at a different teacher. The
    | first is what `isPartyTo`'s second arm opens — an active enrolment anywhere
    | in the workspace — and the second is what no arm opens, which is what
    | SC-002 is measured against.
    */
    $this->siblingCourse = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->foreignCourse = courseWithRate((int) $this->elsewhere->getKey(), 5000);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->child = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Student,
        'first_name' => 'كريم',
    ]);

    $this->guardian = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Parent,
    ]);

    app()->forgetInstance(WorkspaceContext::class);
});

/**
 * The link as the product actually makes one: requested by the guardian, then
 * ACCEPTED by the child over the route a real family walks.
 *
 * ⚠️ `LinkGuardian` refuses anyone whose `platform_role` is not `Parent` (030),
 * so a guardian fixture that skips that column is refused with «لم نجد حساب طالب
 * بهذا المعرّف» and reads as a mistyped uuid.
 */
function acceptedGuardianship(User $guardian, User $student, GuardianPermission ...$permissions): ParentStudentRelation
{
    $relation = app(LinkGuardian::class)->handle($guardian, LinkGuardianData::fromArray([
        'student_name' => $student->name,
        'student_uuid' => $student->uuid,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => array_map(
            static fn (GuardianPermission $permission): string => $permission->value,
            $permissions === [] ? [GuardianPermission::Payments] : $permissions,
        ),
    ]));

    test()->actingAs($student, 'sanctum')
        ->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk();

    return $relation->refresh();
}

/** A way in for the child: one active enrolment at this one teacher. */
function enrolChildIn(User $student, object $course): void
{
    Enrollment::query()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => EnrollmentStatus::Active->value,
        'enrolled_at' => now(),
    ]);
}

function topUpAs(User $caller, string $courseUuid, ?string $studentUuid): TestResponse
{
    return test()->actingAs($caller, 'sanctum')->postJson('/api/v1/billing/purchases', array_filter([
        'course' => $courseUuid,
        'package' => (string) test()->package->uuid,
        'student_uuid' => $studentUuid,
    ], static fn (mixed $value): bool => $value !== null));
}

function priceAs(User $caller, string $courseUuid, ?string $studentUuid): TestResponse
{
    $query = 'course='.urlencode($courseUuid)
        .($studentUuid === null ? '' : '&student_uuid='.urlencode($studentUuid));

    return test()->actingAs($caller, 'sanctum')->getJson('/api/v1/billing/packages?'.$query);
}

/*
|------------------------------------------------------------------------------
| US2 · THE REFUSALS — measured before the happy path, deliberately.
|------------------------------------------------------------------------------
*/

/*
| SC-002 · FR-002. The condition the officer skips and the guardian does NOT.
|
| ⚠️ ON BOTH DOORS. A pricing door guarded more loosely than the buying door
| hands out the number the guard exists to hide: a total is
| `(approved rate + operating fee) × credits`, and two of them on two package
| sizes solve for the platform's constants and then invert every other teacher's
| approved settlement rate. Refusing the sale afterwards is too late.
*/
it('refuses a guardian on a course their child is no party to, on both doors (SC-002)', function (): void {
    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);

    priceAs($this->guardian, (string) $this->foreignCourse->uuid, (string) $this->child->uuid)
        ->assertForbidden()
        ->assertJsonPath('message', 'لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');

    topUpAs($this->guardian, (string) $this->foreignCourse->uuid, (string) $this->child->uuid)
        ->assertForbidden()
        ->assertJsonPath('message', 'لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| FR-006 — the identity probe closed, and the EQUALITY is the requirement rather
| than the wording.
|
| A caller who could tell «no such account» from «not your child» from «no
| payments permission» holds an oracle: pass a uuid, read which refusal comes
| back, and learn whether it names a real person on the platform. Student uuids
| are handed to every seat holder in a live room.
*/
it('answers all three ways of naming the wrong student identically (FR-006)', function (): void {
    $stranger = User::factory()->create(['last_workspace_id' => null]);
    $withoutPayments = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Student,
    ]);

    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    acceptedGuardianship($this->guardian, $withoutPayments, GuardianPermission::Attendance);

    $answers = [
        'not your child' => topUpAs($this->guardian, (string) $this->course->uuid, (string) $stranger->uuid),
        'no payments permission' => topUpAs($this->guardian, (string) $this->course->uuid, (string) $withoutPayments->uuid),
        'no such account' => topUpAs($this->guardian, (string) $this->course->uuid, (string) Str::uuid()),
    ];

    foreach ($answers as $case => $response) {
        expect($response->status())->toBe(422, $case)
            ->and($response->json('message'))->toBe('لا يمكنك الدفع لهذا الطالب.', $case);
    }

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| And a guardian who names NOBODY is refused rather than defaulted to themselves
| — the rule 029 wrote for subscriptions, on this door too. A guardian account is
| not a learner account: `dashboardAudience` reads `parent` and hides every
| student screen, so credits in their name are credits nobody can spend.
*/
it('refuses a guardian who names nobody rather than buying for themselves', function (): void {
    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    topUpAs($this->guardian, (string) $this->course->uuid, null)
        ->assertStatus(422)
        ->assertJsonPath('message', 'اختر الطالب الذي تدفع له.');

    priceAs($this->guardian, (string) $this->course->uuid, null)
        ->assertStatus(422)
        ->assertJsonPath('message', 'اختر الطالب الذي تدفع له.');

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| SC-004 · FR-003 — the officer's skip survives, in BOTH of its shapes.
|
| ⚠️ THE SECOND CASE IS THE ONE NOTHING MEASURED. `makePlatformStaff()` writes no
| workspace pivot at all, so every existing officer fixture is somebody with no
| membership anywhere — and «the officer skips the ways in» was therefore only
| ever tested against a student who also had none. A student who IS on the
| teaching side must still be refused, and one who is simply new must still pass.
*/
it('keeps the officer grant working for a student with no enrolment at all (SC-004)', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $newcomer = User::factory()->create(['last_workspace_id' => null, 'platform_role' => PlatformRole::Student]);

    app(PurchaseCredits::class)->handle($newcomer, $this->course, $this->package, null, $officer);

    expect(Order::query()->withoutWorkspaceScope()->where('user_id', $newcomer->getKey())->count())->toBe(1);
});

it('still refuses an officer granting to somebody on the teaching side (FR-003)', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    expect(fn () => app(PurchaseCredits::class)->handle($assistant, $this->course, $this->package, null, $officer))
        ->toThrow(AuthorizationException::class);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| ⛔ AND THE LEAK THE GUARDIAN CAPACITY OPENS, WHICH NO EXISTING CHECK COULD SEE.
|
| A teacher who is also a parent, whose child is enrolled in that teacher's own
| course, reads their own course's totals for two package sizes THROUGH the child
| — which solve for the platform's two constants, and then invert every OTHER
| teacher's approved settlement rate off any published total (FR-021ب).
| `isPartyTo($child)` cannot see it, because the person being checked is not the
| person reading the screen. So `mayBuyFor()` asks the seller refusal about the
| PAYER as well.
*/
it('refuses a teacher who is a guardian buying on their OWN course through their child', function (): void {
    $this->teacher->forceFill(['platform_role' => PlatformRole::Parent])->save();

    acceptedGuardianship($this->teacher, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    priceAs($this->teacher, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertForbidden()
        ->assertJsonPath('message', 'لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');

    topUpAs($this->teacher, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertForbidden();

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
|------------------------------------------------------------------------------
| US1 · THE TOP-UP, END TO END.
|------------------------------------------------------------------------------
*/

/*
| SC-001 · SC-005 · FR-008.
|
| ⚠️ THE 201 PROVES NOTHING ON ITS OWN. `orders.user_id` is what every listener
| below reads as «the student», so a test that stops at the response passes
| against a build where the money lands on the child and the CREDITS land on the
| guardian. This one approves the order and asks where the balance actually went
| — and asserts the guardian has none, which is the defect in its own words.
*/
it('puts the order, the balance and the credits on the CHILD (SC-001 · SC-005 · FR-008)', function (): void {
    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    // Resources are unwrapped in this app, so the priced list is the root.
    expect(priceAs($this->guardian, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertOk()->json())->toHaveCount(1);

    $orderUuid = topUpAs($this->guardian, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertCreated()
        ->json('order');

    $order = Order::query()->withoutWorkspaceScope()->where('uuid', $orderUuid)->firstOrFail();

    // T022 · FR-008 — the two roles on one row, exactly as 024 defines them.
    expect((int) $order->user_id)->toBe($this->child->getKey())
        ->and((int) $order->granted_by)->toBe($this->guardian->getKey());

    app(ApproveOrder::class)->handle($order, makePlatformStaff(Roles::FINANCE_ADMIN));

    $balance = CreditBalance::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->child->getKey())
        ->where('course_id', $this->course->getKey())
        ->first();

    expect($balance)->not->toBeNull()
        ->and($balance->remaining_credits)->toBe(4)
        // SC-005's negation, which is the bug in one line: nothing in the payer's name.
        ->and(CreditBalance::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->guardian->getKey())->exists())->toBeFalse();
});

/*
| FR-010 · SC-006 — the receipt belongs to whoever uploaded it.
|
| The order is the child's, so `view()` admits them and they read its status, its
| amount and its history. The RECEIPT is a different document: a photograph of the
| guardian's bank transfer, out of the guardian's account. Handing the child a
| signed link to it is a parent's bank statement on a teenager's screen, over an
| order that is legitimately theirs.
|
| ⚠️ AND NOT MINTING THE URL IS THE WHOLE GUARD, WHICH IS UNUSUAL AND DELIBERATE.
| «Hiding a control is not a guard» holds where the door can be knocked on
| directly; `/orders/{order}/receipt` carries no `auth:sanctum` at all and is
| reachable by SIGNATURE ALONE, which only `OrderResource` mints and only for a
| viewer it has already decided may read. The URL is the capability, exactly as a
| playback grant is.
*/
it('does not hand the child a link to a receipt their guardian uploaded (FR-010 · SC-006)', function (): void {
    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    $orderUuid = topUpAs($this->guardian, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertCreated()
        ->json('order');

    Sanctum::actingAs($this->guardian);

    $this->postJson("/api/v1/orders/{$orderUuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        'method' => 'bank_transfer',
    ])->assertOk();

    // The guardian — who uploaded it — keeps their link.
    $mine = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->firstWhere('uuid', $orderUuid);

    expect($mine['receipt_url'])->not->toBeNull();

    // The child, whose order it is, reads the order and NOT the document.
    Sanctum::actingAs($this->child);

    $theirs = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->firstWhere('uuid', $orderUuid);

    expect($theirs)->not->toBeNull()
        ->and($theirs['receipt_url'])->toBeNull()
        // «A receipt exists» is not the receipt: it is what tells them the
        // payment is under way rather than waiting on them.
        ->and($theirs['has_receipt'])->toBeTrue();
});

/*
| And the way in the picker exists for (`isPartyTo`'s SECOND arm): an active
| enrolment ANYWHERE in the teacher's workspace opens every course of that
| teacher. So a child enrolled in one course may have credits bought on another —
| a picker built from enrolments would hide this course while the server accepts
| it, which is SC-003 broken in its quiet direction.
*/
it('accepts a sibling course at the same teacher, which no enrolment list would offer', function (): void {
    acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    topUpAs($this->guardian, (string) $this->siblingCourse->uuid, (string) $this->child->uuid)
        ->assertCreated();
});

/*
| The relation still has to be LIVE at the moment of the request. A guardianship
| revoked after the link was accepted closes the door on the next call — the
| payments permission is not a token the guardian keeps hold of.
*/
it('stops accepting once the guardianship is revoked', function (): void {
    $relation = acceptedGuardianship($this->guardian, $this->child, GuardianPermission::Payments);
    enrolChildIn($this->child, $this->course);

    topUpAs($this->guardian, (string) $this->course->uuid, (string) $this->child->uuid)->assertCreated();

    $relation->forceFill(['status' => RelationStatus::Revoked->value])->save();

    topUpAs($this->guardian, (string) $this->course->uuid, (string) $this->child->uuid)
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكنك الدفع لهذا الطالب.');
});
