<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Listeners\LeaveCohortsOnOrderReversed;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Listeners\ReleaseSeatsOnSubscriptionEnd;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\ReverseCourseOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Events\CourseAccessWithdrawn;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| «عكس الدفع» على طلبِ كورسٍ معتمَد.
|
| ⛔ `ReversePayment` كانَ مُناديه الوحيدُ `CancelSubscription`، فطلبُ كورسٍ
| استُرِدَّ مالُه خارجَ المنصّةِ لم يكنْ له بابٌ: الكورسُ يبقى مفتوحاً والطلبُ
| يقرأُ «معتمَد».
|
| ⚠️ الموظّفُ الماليُّ يملكُ ورشةً أخرى (`last_workspace_id` مختوم)، وهو ما
| يُسلِّحُ التجهيزة: المطالبةُ الشرطيّةُ وإغلاقُ التسجيلِ منطوقَينِ بورشتِه يُصيبانِ
| صفرَ صفوفٍ بلا خطأ — عيبُ ٠٢٤. وتجهيزةٌ بورشةٍ واحدةٍ تمرُّ فوقَه خضراء.
|
| ⚠️ والتسجيلُ والمعاملةُ المحصَّلةُ يُكتَبانِ بالطريقِ الحقيقيّ (`CreateOrder` ثمّ
| `ApproveOrder`) لا بيدٍ، وبلا `Queue::fake()`: المستمِعُ الذي يكتبُ التسجيلَ
| مُصطفّ، وتزييفُ الطابورِ يجعلُ «أُغلِقَ التسجيلُ» توكيداً عن جدولٍ فارغ.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'price_minor' => 4999,
        'is_sequential' => false,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->order = app(CreateOrder::class)->handle($course, $this->student);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    [$decoy] = $this->createWorkspaceWithOwner();
    $this->officer->forceFill(['last_workspace_id' => $decoy->getKey()])->save();

    app(ApproveOrder::class)->handle($this->order, $this->officer);

    $this->actingAs($this->officer);

    /*
    | ⚠️ السياقُ يُعادُ «غيرَ محسوم»، وإلّا بقيَ مُجمَّداً على ما حسمَه الإعدادُ
    | أعلاه ولم تُقرَأْ ورشةُ الموظّفِ المختومةُ قطّ — فتمرُّ مطالبةٌ منطوقةٌ خضراء.
    | `forget()` لا يصلحُ هنا: يُثبِّتُه على null، وهو شخصٌ لا ينتجُه الإنتاج.
    */
    app()->forgetInstance(WorkspaceContext::class);
});

function reversalScreenOrder(Order $order): Order
{
    return Order::query()->withoutWorkspaceScope()->findOrFail($order->getKey());
}

function reversalScreenEnrollment(Order $order): Enrollment
{
    return Enrollment::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->sole();
}

it('starts from a real approval: an open enrolment and a captured payment', function (): void {
    // الحالةُ الابتدائيّةُ مقيسةٌ لا مفترَضة — وإلّا مرَّ «أُغلِقَ» فوقَ تسجيلٍ لم يُفتَحْ قطّ.
    expect(reversalScreenEnrollment($this->order)->status)->toBe(EnrollmentStatus::Active->value)
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()
            ->where('order_id', $this->order->getKey())->sole()->status)->toBe(PaymentStatus::Captured);
});

it('reverses the payment, closes the enrolment, cancels the order and tells the student', function (): void {
    Livewire::test(ListOrders::class)
        ->callTableAction('reverse', $this->order->getKey(), ['reason' => 'استرداد بنكي بطلب الطالب'])
        ->assertHasNoTableActionErrors();

    $transaction = PaymentTransaction::query()->withoutWorkspaceScope()
        ->where('order_id', $this->order->getKey())->sole();

    expect($transaction->status)->toBe(PaymentStatus::Reversed)
        ->and($transaction->failure_reason)->toBe('استرداد بنكي بطلب الطالب')
        ->and(reversalScreenOrder($this->order)->status)->toBe('cancelled')
        ->and(reversalScreenEnrollment($this->order)->status)->toBe(EnrollmentStatus::Cancelled->value)
        ->and(reversalScreenEnrollment($this->order)->grantsContentAccess())->toBeFalse();

    // الإشعارُ من مستمِعِ `PaymentReversed` القائم، مرّةً واحدة — لا ثانٍ من الإجراء.
    $sent = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::PaymentReversed->value)
        ->get();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->body)->toContain('استرداد بنكي بطلب الطالب');
});

it('requires a reason', function (): void {
    Livewire::test(ListOrders::class)
        ->callTableAction('reverse', $this->order->getKey(), ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect(reversalScreenOrder($this->order)->status)->toBe('approved');
});

it('refuses a second reversal with a sentence and changes nothing more', function (): void {
    app(ReverseCourseOrder::class)->handle($this->order, $this->officer, 'الأولى');

    expect(fn () => app(ReverseCourseOrder::class)->handle($this->order, $this->officer, 'الثانية'))
        ->toThrow(DomainException::class);

    expect(PaymentTransaction::query()->withoutWorkspaceScope()
        ->where('order_id', $this->order->getKey())->sole()->failure_reason)->toBe('الأولى');
});

it('offers no reversal on an order that is not approved', function (): void {
    app(ReverseCourseOrder::class)->handle($this->order, $this->officer, 'سبب');

    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('reverse', $this->order->getKey());
});

it('refuses an officer past their two-factor deadline and changes nothing', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    $this->actingAs($this->officer->refresh());

    Livewire::test(ListOrders::class)
        ->callTableAction('reverse', $this->order->getKey(), ['reason' => 'سبب']);

    expect(reversalScreenOrder($this->order)->status)->toBe('approved')
        ->and(reversalScreenEnrollment($this->order)->status)->toBe(EnrollmentStatus::Active->value)
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()
            ->where('order_id', $this->order->getKey())->sole()->status)->toBe(PaymentStatus::Captured);
});

it('refuses the reversal to a teacher, who holds no approval permission', function (): void {
    // المدرّسُ مستفيدُ المال، فلا يكونُ شاهدَ خروجِه — كما في الاعتماد.
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    expect(Gate::forUser($this->teacher)->allows('reverse', reversalScreenOrder($this->order)))->toBeFalse()
        // والسماحُ في الاتّجاهِ الآخر، وإلّا صدقَ الرفضُ على سياسةٍ ترفضُ الجميع.
        ->and(Gate::forUser($this->officer)->allows('reverse', reversalScreenOrder($this->order)))->toBeTrue();
});

/*
| Owner decision 2026-09-23: a reversed course order gives back the student's
| future seats and their place in the group, not only the content.
*/
it('announces the reversed course so its seats and group place are given back', function (): void {
    Event::fake([CourseAccessWithdrawn::class]);

    app(ReverseCourseOrder::class)->handle(reversalScreenOrder($this->order), $this->officer, 'استرداد');

    Event::assertDispatched(
        CourseAccessWithdrawn::class,
        fn (CourseAccessWithdrawn $event): bool => $event->studentUserId === (int) $this->student->getKey()
            && $event->courseIds === [(int) $this->order->course_id],
    );
    Event::assertListening(CourseAccessWithdrawn::class, ReleaseSeatsOnSubscriptionEnd::class);
    Event::assertListening(CourseAccessWithdrawn::class, LeaveCohortsOnOrderReversed::class);
});

it('takes the student out of the group the course runs in', function (): void {
    $membership = CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->order->course_id,
        ])->getKey(),
        'course_id' => $this->order->course_id,
        'student_user_id' => $this->student->getKey(),
    ]);

    app(ReverseCourseOrder::class)->handle(reversalScreenOrder($this->order), $this->officer, 'استرداد');

    expect(CohortMembership::query()->withoutWorkspaceScope()->find($membership->getKey())->closed_at)->not->toBeNull();
});

/*
| ⚠️ `cancelled` is written by the reversal and nothing reopens it by hand — the
| way back is buying again. `EnrollStudent::handOver()` treats any row that no
| longer grants access as lapsed, so the one (workspace, course, student) row is
| reopened under the new order instead of the unique key handing back a closed
| one: money taken twice, curriculum still shut.
*/
it('reopens the cancelled enrolment when the student buys the course again', function (): void {
    app(ReverseCourseOrder::class)->handle(reversalScreenOrder($this->order), $this->officer, 'استرداد');

    expect(reversalScreenEnrollment($this->order)->status)->toBe(EnrollmentStatus::Cancelled->value);

    $course = Course::query()->withoutWorkspaceScope()->findOrFail($this->order->course_id);
    $again = app(CreateOrder::class)->handle($course, $this->student);
    app(ApproveOrder::class)->handle($again, $this->officer);

    $enrollment = reversalScreenEnrollment($again);

    expect($enrollment->status)->toBe(EnrollmentStatus::Active->value)
        ->and($enrollment->grantsContentAccess())->toBeTrue()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('course_id', $course->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->count())->toBe(1);
});
