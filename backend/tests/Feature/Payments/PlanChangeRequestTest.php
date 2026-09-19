<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Actions\DecidePlanChange;
use App\Modules\Payments\Actions\RequestPlanChange;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Enums\PlanChangeStatus;
use App\Modules\Payments\Enums\PlanCoverage;
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
