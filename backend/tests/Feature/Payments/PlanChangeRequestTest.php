<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\DecidePlanChange;
use App\Modules\Payments\Actions\RequestPlanChange;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Exceptions\PlanWouldHideCohorts;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ٠٣٦ — «سعّرتم باقتي وأنا عايز أغيّرها» (قرارُ المالك ٢٠٢٦-٠٩-١٩).
|
| ⛔ THIS FILE IS THE OTHER HALF OF `PlanFormTest`'s REFUSAL. A priced plan may
| not have its shape or coverage moved by a teacher — that is what moves the
| thing the platform priced out from under its price — and a refusal with no way
| through is a teacher opening a support ticket every time, or writing a second
| plan and leaving the first on sale.
|
| ⛔ AND THE OFFICER OWNS A DIFFERENT WORKSPACE. `WorkspaceContext::id()` falls
| back to `users.last_workspace_id` for platform staff like anybody else, so a
| one-workspace fixture makes the scoped and the unscoped query agree and proves
| nothing — the one line that exposed all five layers of ٠٢٤.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->officerWorkspace, $this->officer] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    makePlatformStaff(Roles::FINANCE_ADMIN, $this->officer);
    $this->officer->refresh();

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
            'title' => 'التفاضل',
        ]),
    );

    $this->plan = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Plan => app(SavePlan::class)->handle($this->teacher, (int) $this->workspace->getKey(), [
            'title' => 'حصّة واحدة',
            'session_count' => 1,
            'session_type' => ClassSessionType::Group,
            'coverage_type' => PlanCoverage::Course,
            'coverage_uuid' => (string) $this->course->uuid,
        ]),
    );

    app(SetPlanPrice::class)->handle($this->plan, 10_000);
});

/** @param  array<string, mixed>  $overrides */
function askToChange(array $overrides = []): PlanChangeRequest
{
    return app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): PlanChangeRequest => app(RequestPlanChange::class)->handle(
            test()->teacher,
            test()->plan->fresh(),
            array_merge([
                'session_count' => 12,
                'session_type' => ClassSessionType::Group,
                'coverage_type' => PlanCoverage::Course,
                'coverage_uuid' => (string) test()->course->uuid,
                'reason' => 'المجموعة بقت أصغر.',
            ], $overrides),
        ),
    );
}

function livePlans(): array
{
    return Plan::query()
        ->withoutWorkspaceScope()
        ->where('is_active', true)
        ->orderBy('id')
        ->get()
        ->map(fn (Plan $plan): string => $plan->title.'/'.($plan->session_count ?? '-').'/'.($plan->price_minor ?? 'unpriced'))
        ->all();
}

it('records both sides of every field it is asked about', function (): void {
    /*
    | ⛔ BOTH SIDES, AND THIS IS THE WHOLE REASON THE TABLE HAS TWICE THE COLUMNS.
    | The approval retires the plan this request names and writes a new one, so a
    | month later nothing live still carries what the officer was looking at —
    | and «what exactly did they agree to» would have no answer at all.
    */
    $request = askToChange(['requested_price_minor' => 60_000]);

    expect((int) $request->current_session_count)->toBe(1)
        ->and((int) $request->current_price_minor)->toBe(10_000)
        ->and((int) $request->requested_session_count)->toBe(12)
        ->and((int) $request->requested_price_minor)->toBe(60_000)
        ->and($request->status)->toBe(PlanChangeStatus::Pending)
        // ولا شيءَ تحرّكَ على الباقةِ نفسِها: الطلبُ سؤالٌ لا تنفيذ.
        ->and((int) $this->plan->fresh()->session_count)->toBe(1)
        ->and((int) $this->plan->fresh()->price_minor)->toBe(10_000);
});

it('writes a new plan and retires the old one when the platform agrees', function (): void {
    $request = askToChange(['requested_price_minor' => 60_000]);

    app(DecidePlanChange::class)->handle($request, $this->officer, approve: true);

    /*
    | ⛔ A NEW ROW, NOT AN EDIT. `subscriptions.plan_id` points at the plan that
    | was bought and every screen a subscriber reads takes its terms from there —
    | so editing in place changes what somebody who bought ONE session reads about
    | the thing they bought, quietly, months later.
    */
    expect(livePlans())->toBe(['حصّة واحدة/12/60000'])
        ->and(Plan::query()->withoutWorkspaceScope()->count())->toBe(2)
        ->and($this->plan->fresh()->is_active)->toBeFalse()
        // ⚠️ AND THE OLD ONE KEEPS ITS PRICE: a subscriber's row still names it,
        // and a plan that lost its number reads as one that was never priced.
        ->and((int) $this->plan->fresh()->price_minor)->toBe(10_000);

    $new = Plan::query()->withoutWorkspaceScope()->whereKey($request->fresh()->approved_plan_id)->first();

    /*
    | ⛔ AND IT LANDS IN THE TEACHER'S WORKSPACE, NOT THE OFFICER'S.
    | `BelongsToWorkspace` fills that column from the WRITER's context, and an
    | officer's context falls back to their own `users.last_workspace_id` — so
    | without `forWorkspace()` the approved plan appears in the officer's own
    | catalogue and in no teacher's, with no error anywhere.
    */
    expect($new)->not->toBeNull()
        ->and((int) $new->workspace_id)->toBe((int) $this->workspace->getKey());
});

it('sends the new plan to the pricing queue when the teacher named no number', function (): void {
    /*
    | ⛔ THE OLD PRICE IS NOT CARRIED ACROSS. A request that names no number is a
    | teacher asking for a new shape and leaving the price to the platform, which
    | is the ordinary arrangement — and carrying the old one over would be the
    | platform pricing a thing it has not seen, i.e. the hole this whole flow
    | exists to close, reached from inside the flow.
    */
    $request = askToChange();

    app(DecidePlanChange::class)->handle($request, $this->officer, approve: true);

    $new = Plan::query()->withoutWorkspaceScope()->whereKey($request->fresh()->approved_plan_id)->first();

    expect($new->price_minor)->toBeNull()
        ->and($new->isSellable())->toBeFalse()
        ->and((int) $new->session_count)->toBe(12);
});

it('changes nothing at all when the platform refuses', function (): void {
    $request = askToChange(['requested_price_minor' => 60_000]);

    app(DecidePlanChange::class)->handle($request, $this->officer, approve: false, reason: 'السعر أقل من تكلفة الحصة.');

    expect($request->fresh()->status)->toBe(PlanChangeStatus::Rejected)
        ->and(Plan::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(livePlans())->toBe(['حصّة واحدة/1/10000']);
});

it('tells the teacher a refusal, with the officer\'s reason in it', function (): void {
    $request = askToChange(['requested_price_minor' => 60_000]);

    app(DecidePlanChange::class)->handle($request, $this->officer, approve: false, reason: 'السعر أقل من تكلفة الحصة.');

    // The only producer of `plan_change_rejected`, measured from the decision
    // itself — the approval case below never reached this branch.
    $sent = assertNotifiedOnce($this->teacher, NotificationType::PlanChangeRejected);

    expect((string) $sent->body)->toContain('السعر أقل من تكلفة الحصة.')
        ->and(wasNotified($this->teacher, NotificationType::PlanChangeApproved))->toBeFalse();
});

it('tells the teacher what was decided, with the shape in the sentence', function (): void {
    $request = askToChange();

    app(DecidePlanChange::class)->handle($request, $this->officer, approve: true);

    $sent = Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', 'plan_change_approved')
        ->first();

    /*
    | ⚠️ THE SHAPE IS IN THE BODY, NOT A LINK TO IT. A teacher reading «تمت
    | الموافقة» on a phone at night needs to know what they now sell — a
    | notification whose whole content is a second trip to the panel is the
    | defect this tree already records for the scheduled report.
    |
    | ⚠️ AND THE COUNT GOES THROUGH `CountedNoun`: «١٢ حصّة» is the `many` band,
    | which is exactly where a naive template happens to agree — so the assertion
    | is on the sentence being rendered at all, and `PlanShape`'s own bands are
    | measured where they are written.
    */
    expect($sent)->not->toBeNull()
        ->and($sent->body)->toContain('حصّة');
});

it('refuses a second pending request for one plan', function (): void {
    /*
    | ⛔ TWO ASKS ABOUT ONE ROW ARE TWO DECISIONS THAT CAN DISAGREE — and the
    | second approval would write a third plan from a snapshot taken before the
    | first one landed, leaving the teacher two live plans and one retired.
    */
    askToChange();

    expect(fn () => askToChange(['session_count' => 20]))
        ->toThrow(DomainException::class, 'قيد المراجعة');

    // وبعدَ الحسمِ يُفتَحُ البابُ ثانية: الرفضُ ليسَ إغلاقاً دائماً.
    app(DecidePlanChange::class)->handle(
        PlanChangeRequest::query()->withoutWorkspaceScope()->firstOrFail(),
        $this->officer,
        approve: false,
        reason: 'لا.',
    );

    expect(askToChange(['session_count' => 20])->exists)->toBeTrue();
});

it('refuses a request on a plan nobody has priced', function (): void {
    // بابٌ إلى طابورٍ لا معنى له: ما لم يُسعَّرْ يعدّلُه المدرّسُ من شاشتِه.
    $unpriced = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Plan => app(SavePlan::class)->handle($this->teacher, (int) $this->workspace->getKey(), [
            'title' => 'بلا سعر',
            'duration_days' => 30,
            'session_type' => ClassSessionType::Group,
            'coverage_type' => PlanCoverage::Course,
            'coverage_uuid' => (string) $this->course->uuid,
        ]),
    );

    expect(fn () => app(RequestPlanChange::class)->handle($this->teacher, $unpriced, [
        'session_count' => 12,
        'session_type' => ClassSessionType::Group,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => (string) $this->course->uuid,
    ]))->toThrow(DomainException::class, 'لم تُسعَّر بعد');
});

it('decides once even when two officers press at the same moment', function (): void {
    /*
    | ⛔ A SEQUENTIAL «DECIDE TWICE» PASSES AGAINST A BUILD WITH NO CLAIM IN IT —
    | the second call reads a request that is already decided and stops at the
    | status check. The window is between reading the row and writing it, so the
    | competing decision is performed from INSIDE that read: a `retrieved` hook is
    | the other officer winning in exactly that instant, with no threads and no
    | sleeps. The same trick `InviteFromWaitlist` and `OpenBroadcastRoom` use.
    */
    $request = askToChange(['requested_price_minor' => 60_000]);

    PlanChangeRequest::retrieved(function (PlanChangeRequest $row): void {
        PlanChangeRequest::query()->withoutWorkspaceScope()->whereKey($row->getKey())
            ->where('status', PlanChangeStatus::Pending->value)
            ->update(['status' => PlanChangeStatus::Approved->value, 'decided_at' => now()]);
    });

    try {
        expect(fn () => app(DecidePlanChange::class)->handle($request->fresh(), $this->officer, approve: true))
            ->toThrow(DomainException::class, 'حُسِم بالفعل');
    } finally {
        PlanChangeRequest::flushEventListeners();
    }

    // ولا باقةَ ثانيةً كُتِبَت.
    expect(Plan::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('refuses a decision from somebody who does not price', function (): void {
    // إخفاءُ الصفحةِ ليسَ حراسة: الفعلُ يسألُ الصلاحيّةَ بنفسِه.
    $request = askToChange();

    expect(fn () => app(DecidePlanChange::class)->handle($request, $this->teacher, approve: true))
        ->toThrow(DomainException::class, 'قرار المنصّة');

    expect($request->fresh()->status)->toBe(PlanChangeStatus::Pending);
});

it('carries the ask over the wire and lists it back', function (): void {
    /*
    | ⚠️ THE ROUTE, NOT ONLY THE ACTION. Everything above calls the Action
    | directly, which proves the rule and nothing about the wiring — and this
    | repository has shipped a complete Action behind a route no client could
    | reach more than once. What this adds is the form request: `price_minor` is
    | absent from `SavePlanRequest` on purpose, so the ask needed a request class
    | of its own, and a route bound to the wrong one drops the number in silence.
    */
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    $this->postJson("/api/v1/manage/plans/{$this->plan->uuid}/change-requests", [
        'session_count' => 12,
        'session_type' => ClassSessionType::Group->value,
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => (string) $this->course->uuid,
        'requested_price_minor' => 60_000,
        'reason' => 'المجموعة بقت أصغر.',
    ])->assertCreated()
        ->assertJsonPath('data.requested_price_minor', 60_000)
        // الجملتانِ مبنيّتانِ على الخادم: اشتقاقُهما في TypeScript تهجئةٌ ثانيةٌ
        // لِـ`PlanShape`، وهي التي صرفَت هذه المواصفةُ متطلَّباً كاملاً عليها.
        ->assertJsonPath('data.current_shape', 'حصّة واحدة')
        ->assertJsonPath('data.status', 'pending');

    $this->getJson('/api/v1/manage/plan-change-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.requested_shape', '١٢ حصّة');
});

/*
| ٠٣٦ · FR-013, ON THE OFFICER'S DOOR — «الموافقةُ هي الكتابة، فالتحذيرُ هنا».
|
| ⛔ A TEACHER MAY NOT MOVE A PRICED PLAN, SO THIS REQUEST IS THE ONLY WAY ITS
| COVERAGE EVER NARROWS — and the write happens at the APPROVAL, through a door
| the teacher's own warning never passes. Without this the officer agrees,
| groups with students in them drop out of every picker, and nobody ever sees a
| number.
|
| ⚠️ AND THE WARNING BELONGS HERE RATHER THAN AT THE ASK. Nothing is written
| when the teacher submits a request, so a count there would be a guess about a
| row that does not exist — the modelling `SavePlan` refuses to do. Here the
| write is real, so the gate is read on both sides of it and the difference is
| measured.
*/

/** A group of this course with real membership rows in it — never a bumped counter. */
function seatedCohort(string $name, ?Course $course = null, int $members = 1): Cohort
{
    $cohort = Cohort::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => ($course ?? test()->course)->getKey(),
        'created_by' => test()->teacher->getKey(),
        'name' => $name,
    ]);

    for ($i = 0; $i < $members; $i++) {
        CohortMembershipWriter::open(
            $cohort,
            User::factory()->create(['last_workspace_id' => null]),
            CohortMembershipEvent::JOINED,
            test()->teacher,
        );
    }

    return $cohort->refresh();
}

/**
 * The shape FR-013's second trigger needs: a WORKSPACE-wide price being asked
 * down to one course, while a group in a DIFFERENT course has students in it.
 *
 * ⚠️ IT CANNOT BE BUILT WITH `is_active`. A teacher may switch their own plan
 * off without asking anybody, so a deactivation never reaches this Action —
 * narrowing the coverage is the only edit that has to come through here, which
 * is exactly why the simulation `SavePlan` rejected could not have covered it.
 */
function narrowingRequest(): PlanChangeRequest
{
    $chemistry = app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => test()->workspace->getKey(),
            'created_by' => test()->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
            'title' => 'الكيمياء',
        ]),
    );

    seatedCohort('مجموعة الكيمياء', $chemistry);

    $wide = groupPriceFor(test()->course);
    app(SetPlanPrice::class)->handle($wide, 20_000);

    test()->wide = $wide;

    return app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): PlanChangeRequest => app(RequestPlanChange::class)->handle(
            test()->teacher,
            $wide->fresh(),
            [
                'duration_days' => 30,
                'session_type' => ClassSessionType::Group,
                'coverage_type' => PlanCoverage::Course,
                'coverage_uuid' => (string) test()->course->uuid,
                'reason' => 'هركّز على التفاضل.',
            ],
        ),
    );
}

it('refuses an approval that would hide a group with students in it', function (): void {
    $request = narrowingRequest();

    expect(fn () => app(DecidePlanChange::class)->handle($request->fresh(), $this->officer, approve: true))
        ->toThrow(PlanWouldHideCohorts::class, 'مجموعة الكيمياء');
});

it('leaves the request PENDING after that refusal, with nothing written', function (): void {
    /*
    | ⛔ THE HALF THAT NEEDED THE TRANSACTION, AND IT IS A SEPARATE CASE FROM THE
    | SENTENCE. The status claim used to stand OUTSIDE any transaction, above the
    | write — so a throw left the request recorded APPROVED with no plan behind
    | it and nothing able to decide it again: the `claimForGrading()` defect,
    | where a refusal after a claim strands the row in the state the refusal
    | exists to prevent. A build that threw without wrapping passes the case
    | above word for word and fails here.
    */
    $request = narrowingRequest();
    $before = Plan::query()->withoutWorkspaceScope()->count();

    try {
        app(DecidePlanChange::class)->handle($request->fresh(), $this->officer, approve: true);
    } catch (PlanWouldHideCohorts) {
        // The sentence is measured above; this case is about the rows.
    }

    expect($request->fresh()?->status)->toBe(PlanChangeStatus::Pending)
        ->and(Plan::query()->withoutWorkspaceScope()->count())->toBe($before)
        // ⚠️ AND THE OLD PLAN IS STILL ON SALE. Retiring it is part of the same
        // transaction, so a half-applied decision would leave the teacher with
        // nothing sellable at all.
        ->and((bool) $this->wide->fresh()?->is_active)->toBeTrue();
});

it('approves when the officer says they know', function (): void {
    // ⚠️ THE OTHER HALF OF A QUESTION. FR-013 asks that the decision be
    // INFORMED, not that it be blocked — an officer who still means it goes
    // through, and the old plan is retired as it always was.
    $request = narrowingRequest();

    app(DecidePlanChange::class)->handle(
        $request->fresh(),
        $this->officer,
        approve: true,
        acknowledgeHiddenCohorts: true,
    );

    expect($request->fresh()?->status)->toBe(PlanChangeStatus::Approved)
        ->and((bool) $this->wide->fresh()?->is_active)->toBeFalse();
});

it('says nothing when the approval hides no group anybody is in', function (): void {
    /*
    | ⛔ THE CONTROL, AND WITHOUT IT THE THREE CASES ABOVE ARE EQUALLY TRUE OF A
    | BUILD THAT REFUSES EVERY APPROVAL ON THE PLATFORM. This request moves the
    | shape of a COURSE plan and leaves its coverage where it was, so no group
    | anywhere loses its price.
    |
    | ⛔ AND THE SECOND GROUP IS THE HALF THAT PROVES THE **DIFFERENCE** WAS
    | TAKEN. «مجموعة المساء» carries an unpriced plan of its own, so the overrule
    | rule holds it out of every list ALREADY — «own plan exists» switches the
    | inheritance off whether that plan can be bought or not. It has students in
    | it, and this decision does not touch it.
    |
    | A build that reported «which member-carrying groups are dark AFTER» rather
    | than «which went dark BECAUSE of this» refuses here, and teaches the
    | officer to tick the box on every approval — which is the warning switched
    | off by the hand of the person it was written for.
    */
    seatedCohort('مجموعة التفاضل');

    $evening = seatedCohort('مجموعة المساء');

    Plan::factory()->unpriced()->forCohort((string) $evening->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $request = askToChange(['requested_price_minor' => 60_000]);

    app(DecidePlanChange::class)->handle($request->fresh(), $this->officer, approve: true);

    expect($request->fresh()?->status)->toBe(PlanChangeStatus::Approved);
});

it('tells the teacher WHICH groups the approval took out of the offer', function (): void {
    /*
    | ⛔ ٠٣٦ · FR-013 — THE OFFICER TICKING THE BOX IS THE OFFICER ACCEPTING THE
    | COST, NOT PERMISSION TO KEEP IT FROM THE PERSON WHOSE GROUPS THEY ARE.
    | Before this the teacher read «وافقت الإدارة… الباقة الجديدة تبيع ٣٠ يوماً»
    | and nothing else, and found out a group had gone dark when a student asked
    | why it was not showing.
    |
    | ⚠️ THE ASSERTION IS THE NAME IN THE BODY, never that a row exists: a
    | notification is written for every decision either way, so counting rows is
    | equally true of a build that says nothing about the groups at all.
    */
    $request = narrowingRequest();

    app(DecidePlanChange::class)->handle(
        $request->fresh(),
        $this->officer,
        approve: true,
        acknowledgeHiddenCohorts: true,
    );

    /*
    | ⚠️ THE MODEL, NEVER `->value('body')`. `notifications.body` is a
    | TRANSLATABLE column since ٠٥٥ — a JSON document whose key is the locale —
    | and the query builder applies no cast, so the raw read answers the
    | document (or nothing) rather than the sentence a teacher reads.
    */
    $body = (string) Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', 'plan_change_approved')
        ->latest('id')
        ->first()?->body;

    expect($body)->toContain('مجموعة الكيمياء')
        // ⚠️ AND THE COUNT, WHICH IS WHAT FR-013 ASKS FOR — declined through
        // `CountedNoun`, so one group reads «مجموعة واحدة» and never «١ مجموعة».
        ->and($body)->toContain('مجموعة واحدة فيها طلاب')
        // ⚠️ AND THE HALF THAT STOPS A TEACHER PANICKING: nobody was removed.
        ->and($body)->toContain('يبقون مكانهم');
});

it('still sends the approval when it hid nothing at all', function (): void {
    /*
    | ⛔ THE CASE THAT AN EMPTY VARIABLE WOULD HAVE KILLED, AND KILLED SILENTLY.
    | `TemplateRenderer` counts a present-but-EMPTY variable as MISSING and
    | throws a permanent delivery failure — so «send the sentence only when
    | something was hidden» would drop the WHOLE notification for every ordinary
    | approval, and a teacher whose request cost them nothing would never be told
    | it was approved. `reason` beside it already carries the same fallback.
    */
    $request = askToChange(['requested_price_minor' => 60_000]);

    app(DecidePlanChange::class)->handle($request->fresh(), $this->officer, approve: true);

    /*
    | ⚠️ THE MODEL, NEVER `->value('body')`. `notifications.body` is a
    | TRANSLATABLE column since ٠٥٥ — a JSON document whose key is the locale —
    | and the query builder applies no cast, so the raw read answers the
    | document (or nothing) rather than the sentence a teacher reads.
    */
    $body = (string) Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', 'plan_change_approved')
        ->latest('id')
        ->first()?->body;

    expect($body)->toContain('وافقت الإدارة')
        // ⚠️ SAID OUT LOUD RATHER THAN LEFT OUT. A sentence that appears only
        // when something went wrong is a sentence nobody knows to look for.
        ->and($body)->toContain('ولم تخرج أيّ مجموعة من العرض')
        // ⛔ AND NO VARIABLE NAME SURVIVED INTO THE TEXT — a missing entry in the
        // template's `variables` list prints the placeholder at a teacher.
        ->and($body)->not->toContain('{{');
});
