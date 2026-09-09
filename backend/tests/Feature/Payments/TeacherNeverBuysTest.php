<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;

/*
| A TEACHER NEVER BUYS AND NEVER ENROLS — IN ANY COURSE, THEIRS INCLUDED.
|
| ⚠️ REPORTED AGAINST A LIVE PAGE ON 2026-09-08: the public page of a course
| offered its own owner «اشترك في هذه المجموعة», and the owner went through. Not
| a cosmetic slip — a real `enrollments` row, counted in `enrolled_count`, listed
| in the owner's own «تعلّمي», and on the paid door an order they would then
| approve themselves.
|
| ⚠️ THE HOLE WAS THREE DOORS WIDE AND NOT ONE OF THEM ASKED WHO WAS BUYING.
| `CoursePolicy::view()` allows every PUBLISHED course to every reader, and each
| door's own guards are questions about the THING being bought — is it published,
| is it free, is the plan sellable, is the group joinable, is there a pending
| order. So all three passed.
|
| ⚠️ AND THE RULE IS THE BROAD ONE, decided by the product owner on the same day:
| not «not their own course» but «no course at all». The case that separates the
| two readings is the **subscription** one — `a teacher may not buy ANOTHER
| teacher's subscription` — because its plan is read unscoped and therefore always
| reaches the guard. A file without that case passes against the narrow reading
| and proves the wrong requirement.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    /*
    | ⚠️ THREE COURSES, ONE PER DOOR, AND THEY MAY NOT BE SHARED. A course a plan
    | covers is refused at the FREE door by `courseRequiresPurchase()` (spec 027
    | closed that on purpose), so a single course would make the free control red
    | for a reason that has nothing to do with this rule.
    */
    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'price_minor' => 0])->save();

    $this->paidCourse = courseWithRate((int) $this->workspace->getKey());
    $this->paidCourse->forceFill(['status' => 'published', 'price_minor' => 50_000])->save();

    $this->planCourse = courseWithRate((int) $this->workspace->getKey());
    $this->planCourse->forceFill(['status' => 'published', 'price_minor' => 0])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — فردي',
        'duration_days' => 30,
        'price_minor' => 90_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->planCourse->uuid,
    ]);

    /*
    | ⚠️ `last_workspace_id` NULL AND THE CONTEXT SINGLETON RESET. Nothing on a
    | self-registered student's path writes that column, so a fixture that stamps
    | it measures a person who does not exist.
    */
    $this->student = User::factory()->create(['last_workspace_id' => null]);
    app()->forgetInstance(WorkspaceContext::class);

    /*
    | ⚠️ AND A SECOND CONTROL STUDENT WHO **IS** A WORKSPACE MEMBER, because the
    | development database has six of exactly this shape — a teacher or a seeder
    | putting a student into a workspace with `role = student`. It is the case
    | that killed the first version of this guard: a predicate of «belongs to any
    | workspace» refuses them, which takes buying away from real students.
    |
    | It is also the ONLY shape that reaches `POST /courses/{course}/orders` at
    | all: that route asks `OrderPolicy::create()` for the `orders.create`
    | permission, and spatie's team id is null for anyone who is a member of
    | nothing — so a self-registered student is answered 403 there before this
    | rule is ever consulted. ⚠️ That is a DEAD ROUTE rather than a hole: no file
    | under `frontend/src` posts to it, spec 027 having moved buying to
    | `/billing/subscriptions`. It is guarded here anyway, because a guard added
    | the day a caller appears is a guard nobody remembers to add.
    */
    $this->memberStudent = $this->addWorkspaceMember($this->workspace, 'student');

    /*
    | ⚠️ CREDITS ARE THE FOURTH DOOR, and the one the original report did not
    | name. A session is BOOKED with credits, so leaving this open would have
    | kept the whole road open behind three closed gates: buy credits, book
    | sessions, be a student at your own school by another name.
    */
    $this->package = CreditPackage::factory()->create(['credits' => 4, 'is_active' => true]);
});

/**
 * ⚠️ Named for this file. Pest files share one global function namespace, and
 * `postSelfEnrollment` / `postSubscriptionOrder` are already taken elsewhere with
 * their own signatures — a redeclaration is fatal the moment two files land in
 * one worker, and a single-file run sails past it.
 */
function enrolAsTeacherCheck(User $user, Course $course): TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/courses/'.$course->uuid.'/enroll');
}

it('refuses the course OWNER their own free course — the reported bug', function (): void {
    enrolAsTeacherCheck($this->owner, $this->course)->assertStatus(422);

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a teacher ANOTHER teacher\'s free course — and the layer that refuses is measured, not assumed', function (): void {
    /*
    | ⛔ **TWO LAYERS CAN REFUSE HERE AND WHICH ONE ANSWERS IS NOT FIXED** —
    | measured, not reasoned, and it flipped between two runs of identical
    | application code while only the fixture changed.
    |
    | `{course}` is resolved by implicit binding. When the foreign teacher's
    | context has resolved to their own workspace, `WorkspaceScope` hides the
    | course and the answer is **404** before a controller line runs; when it has
    | not, the request reaches the guard and the answer is **422**. Both are
    | refusals and no row is written either way.
    |
    | ⚠️ SO THIS CASE ASSERTS THE OUTCOME, NOT THE MECHANISM. Pinning either
    | status would be pinning a fixture: 404 goes green the day somebody deletes
    | the guard, and 422 goes red on a legitimate fixture change.
    |
    | ⚠️ AND THE GUARD IS LOAD-BEARING HERE — measured. With `teachesOnPlatform()`
    | forced to `false`, this case answered **201 Created**: the scope did NOT
    | stand in for it. Six of this file's ten cases went red under that bite and
    | all four controls stayed green.
    */
    [$otherWorkspace, $otherTeacher] = $this->createWorkspaceWithOwner();

    $refusal = enrolAsTeacherCheck($otherTeacher, $this->course);

    expect($refusal->status())->toBeIn([404, 422])
        ->and(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and($otherWorkspace->getKey())->not->toBe($this->workspace->getKey());
});

it('refuses a teacher ANOTHER teacher\'s subscription — the broad rule, through the guard itself', function (): void {
    /*
    | ⛔ THE CASE THAT SEPARATES THE BROAD RULE FROM THE NARROW ONE.
    |
    | A guard spelled «the buyer's workspace is the course's workspace» passes
    | every other case in this file and fails only here. The decision taken on
    | 2026-09-08 was «any course at all», so this case IS the requirement.
    |
    | And unlike the enrolment door above, the workspace scope cannot stand in
    | for the guard here: the plan is read unscoped on purpose, so a foreign
    | teacher reaches the check itself.
    */
    [, $otherTeacher] = $this->createWorkspaceWithOwner();

    $this->actingAs($otherTeacher, 'sanctum')
        ->postJson('/api/v1/billing/subscriptions', [
            'plan_uuid' => (string) $this->plan->uuid,
            'mode' => 'private',
        ])
        ->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses an ASSISTANT too — the predicate is the pivot ROLE, not ownership', function (): void {
    /*
    | An assistant holds `courses.update` and helps run the course; buying a
    | place in it is the owner's problem wearing a second hat. A rule written
    | against the OWNER alone would let every assistant on the platform buy
    | their own teacher's course.
    */
    $assistant = $this->addWorkspaceMember($this->workspace, 'assistant-teacher');

    enrolAsTeacherCheck($assistant, $this->course)->assertStatus(422);

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a teacher the PAID course door', function (): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/courses/'.$this->paidCourse->uuid.'/orders')
        ->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a teacher the SUBSCRIPTION door', function (): void {
    /*
    | The door the bug was actually reported through: «اشترك في هذه المجموعة» on
    | the public course page links here.
    */
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/billing/subscriptions', [
            'plan_uuid' => (string) $this->plan->uuid,
            'mode' => 'private',
        ])
        ->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses the course OWNER the credits door — and the layer is the OLD one', function (): void {
    /*
    | ⚠️ **403, NOT 422** — measured. `ParticipationRules::mayBuyFor()` refused
    | the teacher of this course long before spec 033, as an authorization
    | failure, and three existing files assert that status. Adding a second check
    | above it answered first and turned all three red with a correct refusal
    | wearing the wrong code; the check was then deleted entirely — see the case
    | below for why.
    */
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/billing/purchases', [
            'course' => $this->paidCourse->uuid,
            'package' => $this->package->uuid,
        ])
        ->assertStatus(403);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a FOREIGN teacher the credits door too — and NOTHING WAS ADDED TO CLOSE IT', function (): void {
    /*
    | ⛔ THE CASE THAT DELETED A GUARD RATHER THAN ADDING ONE.
    |
    | Credits are what a session is booked with, so this door was investigated as
    | the fourth of four and a `teachesOnPlatform()` check was written into
    | `PurchaseCredits`. Measuring it removed it: `mayBuyFor()` already refuses
    | EVERY teacher-side account here — the course's own teacher and a stranger
    | from another academy alike — with a **403**, and the added check only
    | changed the status code on refusals that were already correct.
    |
    | ⚠️ THE COST OF LEAVING IT IN WOULD HAVE BEEN TWO SPELLINGS OF ONE QUESTION
    | on one door, which is the defect this repository records more often than
    | any other. What is kept is these two cases: they pin the door SHUT without
    | claiming which layer holds it, so a future change to `mayBuyFor()` that
    | opens it goes red here.
    */
    [, $otherTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    $this->actingAs($otherTeacher, 'sanctum')
        ->postJson('/api/v1/billing/purchases', [
            'course' => $this->paidCourse->uuid,
            'package' => $this->package->uuid,
        ])
        ->assertStatus(403);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});

/*
| ⛔ FOUR MANDATORY CONTROLS, ONE PER DOOR — AND THREE CASES RATHER THAN ONE.
|
| Every assertion above is an ABSENCE, and an absence is also what a broken
| route, a mistyped uuid or a fixture that never published anything produces.
| Without these the file would pass over doors that refuse everybody.
|
| ⚠️ Written as a single walk they shared a database, and it went red over
| correct code: the free enrolment gives the student an active enrolment in the
| very course the plan covers, so the subscription was then refused for a reason
| that has nothing to do with this rule. A red control over a correct guard is
| the fastest way to get a real guard deleted.
*/
it('CONTROL — a real student still enrols free', function (): void {
    enrolAsTeacherCheck($this->student, $this->course)->assertCreated();

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('CONTROL — a workspace-member student still orders a paid course', function (): void {
    $this->actingAs($this->memberStudent, 'sanctum')
        ->postJson('/api/v1/courses/'.$this->paidCourse->uuid.'/orders')
        ->assertCreated();

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('CONTROL — a real student still buys a subscription', function (): void {
    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/billing/subscriptions', [
            'plan_uuid' => (string) $this->plan->uuid,
            'mode' => 'private',
        ])
        ->assertCreated();

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('CONTROL — a student who IS a workspace member is not mistaken for a teacher', function (): void {
    /*
    | ⛔ THE CASE THAT KILLED THE FIRST VERSION OF THIS GUARD, and the reason the
    | predicate reads the pivot ROLE rather than the membership. Six rows of
    | exactly this shape sit in the development database; a rule of «belongs to
    | any workspace» refuses every one of them, which is the reported bug's
    | mirror image and costs the platform its revenue instead of a stray row.
    */
    enrolAsTeacherCheck($this->memberStudent, $this->course)->assertCreated();

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('CONTROL — a real student still buys credits', function (): void {
    $this->actingAs($this->memberStudent, 'sanctum')
        ->postJson('/api/v1/billing/purchases', [
            'course' => $this->paidCourse->uuid,
            'package' => $this->package->uuid,
        ])
        ->assertCreated();

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| ⛔ SOMEBODY ELSE MAY BUY FOR A STUDENT, AND THAT MUST KEEP WORKING — raised by
| the product owner on 2026-09-09, and it is the failure this guard could most
| easily have shipped.
|
| A guardian buys for their child, and a finance officer grants on a family's
| behalf. Either of them may themselves own a workspace: a teacher whose own
| child studies elsewhere is an ordinary person, and a platform officer with a
| `last_workspace_id` is the exact fixture spec 024 needed to expose five defects
| at once.
|
| ⚠️ SO THE GUARD IS ASKED OF `$student` AND NEVER OF THE CALLER. Written the
| obvious way — `currentUser($request)->teachesOnPlatform()` — it refuses the
| PAYER and lets the purchase through for everyone else: it would block the two
| people who legitimately buy on somebody's behalf and block nobody it was
| written for. These two cases are what make that mistake red.
*/
/*
| ⚠️ NAMED FOR THIS FILE, because a Pest helper is a GLOBAL function. It was
| `relateGuardian()`, which `LiveSessions/ChildScheduleGuardTest.php` also
| declares with a different signature — invisible while each file gets its own
| process, and a fatal «Cannot redeclare function» the moment one worker loads
| both, which is every `pest --parallel` run and any invocation naming the two
| directories together. Same family as the `DRAFT_SENTINEL` collision this
| repository already paid for.
*/
function relatePayingGuardian(User $guardian, User $child): void
{
    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $child->getKey(),
        'student_name' => $child->first_name,
        'relation_type' => RelationType::Parent->value,
        'permissions' => [GuardianPermission::Payments->value],
        'status' => RelationStatus::Active->value,
    ]);
}

it('lets a guardian WHO OWNS A WORKSPACE buy a subscription for their child', function (): void {
    [, $guardianTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية وليّ الأمر']);

    $child = User::factory()->create(['last_workspace_id' => null]);
    relatePayingGuardian($guardianTeacher, $child);

    app()->forgetInstance(WorkspaceContext::class);

    $this->actingAs($guardianTeacher, 'sanctum')
        ->postJson('/api/v1/billing/subscriptions', [
            'plan_uuid' => (string) $this->plan->uuid,
            'mode' => 'private',
            'student_uuid' => (string) $child->uuid,
        ])
        ->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    // ⚠️ The ORDER belongs to the CHILD, not to whoever paid — «صاحب الطلب
    // والرصيد، حتّى حين لم يلمس لوحة مفاتيح».
    expect((int) $order->user_id)->toBe((int) $child->getKey());
});

it('still refuses that same guardian when they buy for THEMSELVES', function (): void {
    /*
    | The mirror, and it is what makes the case above a guard rather than a hole:
    | the identical account, the identical endpoint, one field different.
    */
    [, $guardianTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية وليّ الأمر']);

    app()->forgetInstance(WorkspaceContext::class);

    $this->actingAs($guardianTeacher, 'sanctum')
        ->postJson('/api/v1/billing/subscriptions', [
            'plan_uuid' => (string) $this->plan->uuid,
            'mode' => 'private',
        ])
        ->assertStatus(422);

    expect(Order::query()->withoutWorkspaceScope()->count())->toBe(0);
});
