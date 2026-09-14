<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\CohortDirectory;

/*
| ٠٣٤ · FR-016 · FR-024أ — **المقعدُ يُطالَبُ به قبلَ قبضِ المال.**
|
| ⛔ **إعادةُ القياسِ قراءةٌ لا مُطالَبة، والمواصفةُ تقولُها بدلَ أن تَعِدَ بما لا
| تملك.** موظَّفانِ يعتمدانِ طلبَينِ على آخرِ مقعدٍ يمرّانِ كلاهما من
| `isJoinable()` ويقبضانِ كلاهما، وأحدُ الطالبَينِ يبقى بلا مجموعة — أي استردادٌ
| **بالتعريف**، و`SC-009` («صفرُ استردادٍ سببُه الامتلاء») يصيرُ وعداً لا يُقاس.
|
| ⚠️ **والحالةُ الثانيةُ هنا هي التي تمنعُ العطبَ الأكبر**: اشتراطُ مجموعةٍ حيثُ
| لا توجدُ واحدةٌ يجعلُ **طلباً مدفوعاً لا يُعتمَدُ أبداً** — وهو عطبُ FR-015
| نفسُه، منقولاً خطوةً إلى الوراء.
|
| ⚠️ **وقراءتانِ مستقلّتانِ للصفِّ نفسِه**، لا المثيلُ الواحدُ مرّتَين: الثاني
| يقرأُ صفّاً حُمِّلَ قبلَ أن يُثبِّتَ الأوّل — وهو ما يحملُه الطلبُ الثاني فعلاً
| (سابقةُ `ApprovalConcurrencyTest`).
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);

    $this->first = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->second = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
});

function courseOrderFor(User $student): Order
{
    return Order::create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => $student->getKey(),
        'course_id' => test()->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'under_review',
    ]);
}

/**
 * ⚠️ `(string)` عندَ كلِّ تمرير: المصنعُ يكتبُ `Str::uuid()` — كائناً لا نصّاً —
 * فالمثيلُ في الذاكرةِ يحملُ الكائنَ بينما الصفُّ المقروءُ من القاعدةِ يحملُ نصّاً.
 * وهذا خطأُ تركيبةٍ لا خطأُ توقيع.
 */
function lastSeatCohort(): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => test()->course->getKey(),
        'name' => 'مجموعة السبت',
        'capacity' => 1,
        'members_count' => 0,
        'created_by' => test()->owner->getKey(),
    ]);
}

it('gives the last seat to the first approval and takes no money from the second', function (): void {
    // ⚠️ متتاليةٌ عمداً: ما يُقاسُ هنا هو **أثرُ** الترتيب — الفائزُ أخذَ المقعدَ
    // ودفعَ، والخاسرُ لم يُقبَضْ منه شيءٌ وبقيَ طلبُه معلَّقاً. والمُطالَبةُ
    // نفسُها مقيسةٌ في الحالةِ التي تليها.

    $cohort = lastSeatCohort();

    $winner = courseOrderFor($this->first);
    $loser = courseOrderFor($this->second);

    $approve = app(ApproveOrder::class);

    $approve->handle($winner, $this->owner, cohortUuid: (string) $cohort->uuid);

    expect(fn () => $approve->handle($loser, $this->owner, cohortUuid: (string) $cohort->uuid))
        ->toThrow(DomainException::class);

    /*
    | ⚠️ **الثلاثةُ معاً، لا الرميةُ وحدَها.** رميةٌ بعدَ قبضِ المالِ هي بالضبطِ
    | الحالُ التي يمنعُها `FR-024أ`، فالتوكيدُ على أنّ الطلبَ **ما زالَ
    | معلَّقاً** وأنّه **لا حركةَ دفعٍ ثانية** هو ما يقيسُ الترتيب.
    */
    expect($loser->refresh()->status)->toBe('under_review')
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and((int) $cohort->refresh()->members_count)->toBe(1);
});

it('refuses the choice that filled while the officer was reading the receipt', function (): void {
    /*
    | ⚠️ **الحالةُ التي انقلبَ لها ترتيبُ الفحصِ في الفعل.** كُتِبَ أوّلاً «إن لم
    | تبقَ مجموعةٌ صالحةٌ فامضِ بلا مجموعة» **فوقَ** قراءةِ ما اختارَه الموظَّف —
    | فكانَ الطلبُ يُعتمَدُ **والمالُ يُقبَضُ** والطالبُ بلا مجموعة، وهي بعينُها
    | الحالُ التي كُتِبَت `FR-024أ` لأجلِها.
    */
    $cohort = lastSeatCohort();

    $order = courseOrderFor($this->first);

    // امتلأَت بينَ فتحِ الشاشةِ وضغطِ الزرّ.
    $cohort->forceFill(['members_count' => 1])->save();

    expect(fn () => app(ApproveOrder::class)->handle($order, $this->owner, cohortUuid: (string) $cohort->uuid))
        ->toThrow(DomainException::class);

    expect($order->refresh()->status)->toBe('under_review')
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets exactly one of two claims on the last seat through', function (): void {
    /*
    | ⛔ **المُطالَبةُ نفسُها، مقيسةً وحدَها.** القراءةُ فوقَها تُعطي الجملةَ في
    | الحالةِ العاديّة، وموظَّفانِ في اللحظةِ نفسِها يمرّانِ كلاهما منها — فما
    | يفصِلُ بينَهما هو هذه الجملةُ الشرطيّةُ الواحدة، ولا شيءَ غيرُها.
    |
    | ⚠️ ومقيسةٌ على البدائيّةِ مباشرةً لا بتمثيلِ تزامنٍ لا يستطيعُه اختبار:
    | تمثيلٌ متتالٍ يمرُّ من القراءةِ ويُثبِتُ القراءةَ، لا المُطالَبة.
    */
    $cohort = lastSeatCohort();
    $directory = app(CohortDirectory::class);

    expect($directory->claimSeat((int) $cohort->getKey()))->toBeTrue()
        ->and($directory->claimSeat((int) $cohort->getKey()))->toBeFalse()
        ->and((int) $cohort->refresh()->members_count)->toBe(1);
});

it('approves a course with no groups at all, with no choice asked for', function (): void {
    /*
    | ⛔ **الحالةُ التي تمنعُ «طلباً مدفوعاً لا يُعتمَدُ أبداً».** حقلٌ مطلوبٌ على
    | كلِّ طلبٍ يُغلِقُ كلَّ كورسٍ مسجَّلٍ على المنصّة — وهو عطبُ FR-015 نفسُه.
    */
    $order = courseOrderFor($this->first);

    app(ApproveOrder::class)->handle($order, $this->owner);

    expect($order->refresh()->status)->toBe('approved')
        ->and(CohortMembership::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('approves a course whose only group is full, rather than stranding the payment', function (): void {
    /*
    | ⚠️ **والامتلاءُ يُقرَأُ «لا وجهةَ» لا «اختَرْ وجهةً»**: كورسٌ امتلأَت
    | مجموعاتُه كلُّها يُعتمَدُ بلا مجموعة ويعملُ FR-015 — منهجُه مفتوحٌ حتّى
    | تُسنِدَه الإدارة.
    */
    Cohort::factory()->full()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $order = courseOrderFor($this->first);

    app(ApproveOrder::class)->handle($order, $this->owner);

    expect($order->refresh()->status)->toBe('approved');
});

it('refuses to approve a grouped course with no group named', function (): void {
    // FR-016ب — الاشتراطُ في الفعلِ لا في الشاشة: البابُ البرمجيُّ يُنادى كما
    // يُنقَرُ الزرّ، وحارسٌ على أحدِهما يترُكُ الآخرَ مفتوحاً.
    lastSeatCohort();

    $order = courseOrderFor($this->first);

    expect(fn () => app(ApproveOrder::class)->handle($order, $this->owner))
        ->toThrow(DomainException::class);

    expect($order->refresh()->status)->toBe('under_review')
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a group that belongs to another course', function (): void {
    $otherCourse = courseWithRate((int) $this->workspace->getKey(), 5000);

    lastSeatCohort();

    $foreign = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $otherCourse->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $order = courseOrderFor($this->first);

    expect(fn () => app(ApproveOrder::class)->handle($order, $this->owner, cohortUuid: (string) $foreign->uuid))
        ->toThrow(DomainException::class);

    expect(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('claims the seat exactly once across the approval and the membership write', function (): void {
    /*
    | ⛔ **`T021أ` — المقعدُ يُطالَبُ به مرّةً واحدةً في المسارِ كلِّه.**
    | `ApproveOrder` يُطالِبُ قبلَ المال، و`CohortMembershipWriter::open()` يُطالِبُ
    | بمقعدِه بنفسِه — فبلا الرايةِ يصيرُ العدّادُ **٢** لطالبٍ واحد: السعةُ
    | تتسرّبُ مقعداً في كلِّ اعتماد، **وقد تُرفَضُ الزيادةُ الثانيةُ بـ«مكتملة»
    | بعدَ أن قُبِضَ المال**.
    |
    | ⚠️ والمستمِعُ يعملُ على `sync` هنا، فالعضويّةُ تُكتَبُ داخلَ هذا النداء.
    */
    $cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'capacity' => 5,
        'members_count' => 0,
        'created_by' => $this->owner->getKey(),
    ]);

    $order = courseOrderFor($this->first);

    app(ApproveOrder::class)->handle($order, $this->owner, cohortUuid: (string) $cohort->uuid);

    $membership = CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->first->getKey())->get();

    expect($membership)->toHaveCount(1)
        ->and((int) $cohort->refresh()->members_count)->toBe(1);

    // والسجلُّ يقولُ مَن أسنَد، لا «انضمَّ الطالب» (SC-002).
    $row = CohortMembershipEvent::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->first->getKey())->sole();

    expect($row->event)->toBe(CohortMembershipEvent::ASSIGNED)
        ->and((int) $row->actor_user_id)->toBe((int) $this->owner->getKey());
});

it('claims the seat exactly once on the subscription door too', function (): void {
    /*
    | ⛔ **بابُ الاشتراكِ بابٌ ثانٍ للمُطالَبةِ نفسِها، و`T027` شُحِنَ بلا حالةٍ
    | على مسارِه.** `ApproveOrder` يُطالِبُ بالمقعدِ لكلِّ طلبٍ يحملُ مجموعةً —
    | لا لطلبِ الكورسِ وحدَه — و`ActivateSubscription` هو الكاتبُ هناك. فإن
    | افترقَ البابانِ في رايةِ «المقعدُ مُطالَبٌ به سلفاً» صارَ العدّادُ **٢**
    | لطالبٍ واحدٍ أو **٠** بعضويّةٍ قائمة، وكلاهما يضعُ الطالبَ التاليَ فوقَ
    | السعة (SC-002 · FR-024أ).
    */
    $this->course->forceFill(['status' => 'published'])->save();

    $plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'capacity' => 5,
        'members_count' => 0,
        'created_by' => $this->owner->getKey(),
    ]);

    $order = app(PurchaseSubscription::class)->handle(
        $this->first,
        (string) $plan->uuid,
        'cohort',
        (string) $cohort->uuid,
    );

    app(ApproveOrder::class)->handle($order, $this->owner);

    expect((int) $cohort->refresh()->members_count)->toBe(1);

    $row = CohortMembershipEvent::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->first->getKey())->sole();

    expect($row->event)->toBe(CohortMembershipEvent::ASSIGNED)
        ->and((int) $row->actor_user_id)->toBe((int) $this->owner->getKey());
});

it('claims the seat itself when the payment came through the gateway door', function (): void {
    /*
    | ⛔ **الرايةُ مشروطةٌ لا مطلَقة، وهذه هي الجهةُ المقابلةُ من التسرّب.**
    | `ApproveOrder` وحدَه يُطالِبُ بالمقعدِ قبلَ المال، و**بابُ بوّابةِ الدفعِ
    | لا يمرُّ به** — فرايةٌ مطلَقةٌ `true` في `ActivateSubscription` تكتبُ
    | العضويّةَ **والعدّادُ لم يزدْ قطّ**: المجموعةُ تقرأُ مقعداً شاغراً لا وجودَ
    | له، ويُوضَعُ الطالبُ التالي فوقَ السعة.
    |
    | ⚠️ ولا طلبَ اشتراكٍ يمرُّ من ذلكَ البابِ اليومَ (كلُّها `manual`)، وهو
    | بالضبطِ سببُ كتابةِ الحالةِ الآن: بابٌ بلا حالةٍ هو بابٌ لا يقيسُه شيءٌ
    | حتّى يُفتَح.
    */
    $this->course->forceFill(['status' => 'published'])->save();

    $plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'capacity' => 5,
        'members_count' => 0,
        'created_by' => $this->owner->getKey(),
    ]);

    $order = app(PurchaseSubscription::class)->handle(
        $this->first,
        (string) $plan->uuid,
        'cohort',
        (string) $cohort->uuid,
    );

    // بوّابةٌ قبضَت: الطلبُ مدفوعٌ و**لا مُعتمِدَ عليه**.
    $order->forceFill(['status' => 'approved', 'approved_by' => null])->save();

    app(ActivateSubscription::class)->handle(new PaymentCaptured(
        $order,
        PaymentTransaction::create([
            'workspace_id' => $this->workspace->getKey(),
            'order_id' => $order->getKey(),
            'provider' => 'stripe',
            'amount_minor' => (int) $order->amount_minor,
            'currency' => 'QAR',
            'status' => PaymentStatus::Captured,
            'reference' => 'REF-GATEWAY-COHORT',
        ]),
    ));

    expect(CohortMembership::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->first->getKey())->count())->toBe(1)
        ->and((int) $cohort->refresh()->members_count)->toBe(1);
});
