<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Filament\Pages\GrantCreditSubscription;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| Spec 024 — منحُ اشتراكِ حصصٍ بيدِ موظّفِ المنصّة.
|
| ⚠️ TWO WORKSPACES, AND THAT IS NOT DECORATION. Every write behind a platform
| permission in this product has to be measured across two of them: a fixture
| with one makes "the officer sees the right rows" true by having no wrong rows
| to see, and it is how `ExecuteTeacherOffboarding` shipped unable to complete an
| exit for an officer whose own workspace differed.
|
| ⚠️ AND THE STUDENT IS BUILT WITH NOTHING. `User::factory()` alone — no seeder,
| no `addWorkspaceMember`, no `last_workspace_id`. A real student is a member of
| no workspace at all, so a fixture that grants them one hands them a way in that
| production never gives them, and the whole point of FR-004 is that they have
| none.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspaceA, $this->teacherA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);
    [$this->workspaceB, $this->teacherB] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية سلمى']);

    $this->courseA = courseWithRate((int) $this->workspaceA->getKey(), 5000);
    $this->courseB = courseWithRate((int) $this->workspaceB->getKey(), 5000);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'ثماني حصص',
        'credits' => 8,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    // The person the whole feature exists for: an account and nothing else.
    $this->student = User::factory()->create(['first_name' => 'سارة', 'last_name' => 'ع']);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

/** The grant, as the officer's screen performs it. */
function grant(?User $student = null, ?object $course = null): CreditPurchase
{
    $test = test();

    return app(PurchaseCredits::class)->handle(
        $student ?? $test->student,
        $course ?? $test->courseA,
        $test->package,
        null,
        $test->officer,
    );
}

function creditsOn(User $student, object $course): int
{
    return (int) (CreditBalance::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->where('course_id', $course->getKey())
        ->value('remaining_credits') ?? 0);
}

// ── T012 · FR-003 · SC-003 · SC-009 ────────────────────────────────────────

it('grants to a student with no enrolment and no membership, and mints nothing yet', function (): void {
    $purchase = grant();

    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    expect($order->status)->toBe('pending')
        ->and($purchase->credits)->toBe(8)
        /*
        | The snapshot is the sale, frozen — spec 015's books read it. ⚠️ AND ALL
        | FOUR AMOUNTS ARE FOR THE WHOLE PURCHASE, not per credit: 5000 × 8 for
        | the teacher and 500 × 8 for the platform. `teacher_rate_minor` reads
        | like a per-unit rate and is not one, so the identity is asserted rather
        | than the convention remembered — the first three ADD UP to the fourth.
        */
        ->and($purchase->teacher_rate_minor)->toBe(40_000)
        ->and($purchase->operating_fee_minor)->toBe(4_000)
        ->and($purchase->gateway_fee_minor)->toBe(0)
        ->and($purchase->total_minor)->toBe(44_000)
        ->and($purchase->teacher_rate_minor + $purchase->operating_fee_minor + $purchase->gateway_fee_minor)
        ->toBe($purchase->total_minor);

    /*
    | ⚠️ READ THE ROW, NOT THE RETURN VALUE. FR-003 is a claim about the
    | database at this moment, and a `CreditBalance` that does not exist yet
    | reads as zero exactly as an untouched one does — which is the answer.
    */
    expect(creditsOn($this->student, $this->courseA))->toBe(0)
        ->and(CreditLot::query()->withoutWorkspaceScope()->count())->toBe(0);
});

// ── T013 · FR-009 ──────────────────────────────────────────────────────────

it('puts the credits on that teacher and no other when the grant is approved', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $order->forceFill(['status' => 'approved', 'approved_by' => $this->officer->getKey()])->save();
    app(RecordCreditPurchase::class)->handle($order);

    expect(creditsOn($this->student, $this->courseA))->toBe(8)
        // The other teacher's course is the control: a balance is per course, and
        // nothing about a grant is portable between them.
        ->and(creditsOn($this->student, $this->courseB))->toBe(0)
        ->and(CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))->toBe(8);
});

// ── T014 · FR-005 ──────────────────────────────────────────────────────────

it('refuses a grant to somebody who teaches the course', function (): void {
    /*
    | ⚠️ THIS IS THE TEST THAT PROVES THE SKIP IS PARTIAL.
    |
    | FR-004 lets the officer past the ways in; skip the whole predicate and this
    | passes silently — and two totals on two package sizes then solve for the
    | platform's constants and invert every OTHER teacher's approved settlement
    | rate. Removing `isSeller()` from `PurchaseCredits` must turn this red.
    */
    expect(fn () => grant($this->teacherA))->toThrow(AuthorizationException::class);

    expect(Order::query()->withoutWorkspaceScope()->where('user_id', $this->teacherA->getKey())->count())->toBe(0);
});

it('refuses to price a grant for somebody who teaches the course', function (): void {
    // The same refusal on the preview read — otherwise the screen shows a price
    // for a grant the save will reject.
    expect(fn () => app(ListCreditPackages::class)->handle($this->teacherA, $this->courseA, $this->officer))
        ->toThrow(AuthorizationException::class);
});

it('prices the packages for a brand-new student so the officer sees the amount first', function (): void {
    // FR-006. Without the skip this is an empty list for the common case, and
    // the officer reads "nothing to sell" about a course that sells fine.
    $offers = app(ListCreditPackages::class)->handle($this->student, $this->courseA, $this->officer);

    expect($offers)->toHaveCount(1)
        ->and($offers[0]['price']->totalMinor)->toBe(44_000);

    // And the student's own read is unchanged: they are party to nothing.
    expect(fn () => app(ListCreditPackages::class)->handle($this->student, $this->courseA))
        ->toThrow(AuthorizationException::class);
});

// ── T015 · FR-004أ ─────────────────────────────────────────────────────────

it('creates no enrolment, so a grant buys sessions and not the course content', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);
    $order->forceFill(['status' => 'approved'])->save();
    app(RecordCreditPurchase::class)->handle($order);

    expect(Enrollment::query()->withoutWorkspaceScope()->where('student_user_id', $this->student->getKey())->count())
        ->toBe(0);
});

// ── T016 · FR-008ب · SC-010 ────────────────────────────────────────────────

it('records the creator and the approver as two separate facts', function (): void {
    $purchase = grant();

    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    expect($order->user_id)->toBe($this->student->getKey())
        ->and($order->granted_by)->toBe($this->officer->getKey())
        ->and($order->grantor->getKey())->toBe($this->officer->getKey());
});

it('leaves granted_by null when the student bought it themselves', function (): void {
    /*
    | ⚠️ THE OTHER DIRECTION, AND IT IS NOT SYMMETRY FOR ITS OWN SAKE. One
    | direction alone passes over an implementation that stamps the officer on
    | every order on the platform — including the ones nobody granted.
    */
    $buyer = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);

    $purchase = app(PurchaseCredits::class)->handle($buyer, $this->courseA, $this->package);

    expect(Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id)->granted_by)->toBeNull();
});

// ── T017 ───────────────────────────────────────────────────────────────────

it('ignores granted_by arriving by mass assignment', function (): void {
    /*
    | It decides an audit fact, so it is written by the Action and by nothing
    | else — the `captured_order_id` rule. Mass-assignable it becomes a second
    | door to that fact from outside the transaction that owns it, and this test
    | is what fails if a later tidy-up adds it to `$fillable`.
    */
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $order->update(['granted_by' => $this->teacherA->getKey(), 'rejection_reason' => 'مسموح']);

    expect($order->refresh()->granted_by)->toBe($this->officer->getKey())
        // The control: the same call DID write a fillable field, so this is a
        // guard passing rather than an update that silently did nothing at all.
        ->and($order->rejection_reason)->toBe('مسموح');
});

// ── T011 · R6 · SC-006 ─────────────────────────────────────────────────────

it('lets an officer who owns a workspace read and approve an order in another one', function (): void {
    /*
    | ⚠️ THE FIXTURE IS THE TEST HERE.
    |
    | `BasePolicy` is explicit that a RESOLVED context which does not match is
    | still a denial, and `WorkspaceContext::id()` falls back to
    | `users.last_workspace_id` for every user — a platform officer included. So
    | an officer who also owns a workspace was refused every order outside it.
    | It stayed invisible because every fixture builds that officer with no
    | workspace at all, where a null context raises no objection.
    */
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    // The officer is standing in workspace B; the order lives in A.
    $this->setCurrentWorkspace($this->workspaceB, $this->officer);

    Sanctum::actingAs($this->officer);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect($order->refresh()->status)->toBe('approved');
});

it('lets an officer with no workspace of their own do the same', function (): void {
    // The case every existing fixture already covered — kept so the pair reads
    // as "both, always" rather than as the one that happened to be written.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    app(WorkspaceContext::class)->forget();
    Sanctum::actingAs($this->officer);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect($order->refresh()->status)->toBe('approved');
});

it('still refuses the teacher whose workspace the order sits in', function (): void {
    // The reorder must not have widened anything: a credit purchase is the
    // platform's sale, and the teacher is the payee downstream.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceA, $this->teacherA);
    Sanctum::actingAs($this->teacherA);

    $this->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertForbidden();

    expect($order->refresh()->status)->toBe('pending');
});

it('lets the officer attach the receipt that arrived outside the product', function (): void {
    // FR-007 — the student never opened the app, so the image is in the
    // officer's hands and nowhere else.
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceB, $this->officer);
    Sanctum::actingAs($this->officer);

    $this->post(
        "/api/v1/orders/{$order->uuid}/receipt",
        ['receipt' => UploadedFile::fake()->image('receipt.jpg')],
    )->assertOk();

    expect($order->refresh()->hasMedia('receipt'))->toBeTrue();
});

// ── T020 · T021 · FR-013 · FR-017 ──────────────────────────────────────────

it('opens the grant screen for the officer and for nobody in a tenant role', function (): void {
    /*
    | ⚠️ BOTH DIRECTIONS, AND THE ALLOW IS THE ONE THAT CATCHES A DEAD GUARD.
    |
    | A permission classified by absence passes every deny test while guarding
    | nothing — `taxonomy.manage` shipped that way. And the tenant owner is the
    | highest role there is, which is exactly why it must fail here: a teacher
    | who can grant credits on their own course is a teacher minting their own
    | income, and spec 014 pays them out of those same credits.
    */
    $teacher = $this->addWorkspaceMember($this->workspaceA, Roles::TEACHER);

    foreach ([$this->teacherA, $teacher] as $tenant) {
        $this->actingAs($tenant);
        app(WorkspaceContext::class)->set($this->workspaceA);

        expect(GrantCreditSubscription::canAccess())->toBeFalse();
    }

    $this->actingAs($this->officer);
    app(WorkspaceContext::class)->forget();

    expect(GrantCreditSubscription::canAccess())->toBeTrue();
});

// ── T024 · T025 · T026 · US2 ───────────────────────────────────────────────

it('names the grantor to the approver and to nobody else', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceB, $this->officer);
    Sanctum::actingAs($this->officer);

    expect($this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()->json('granted_by_name'))
        ->toBe($this->officer->name);

    /*
    | ⚠️ ABSENT, NOT NULL, ON THE STUDENT'S OWN LIST. «A member of staff called X
    | made this for you» is a colleague's name on a customer's screen, and a null
    | key still says the field exists.
    */
    app(WorkspaceContext::class)->forget();
    Sanctum::actingAs($this->student);

    expect($this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()->json())
        ->not->toHaveKey('granted_by_name');
});

it('lets the officer reject a grant with a reason, and moves no credits', function (): void {
    $purchase = grant();
    $order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->setCurrentWorkspace($this->workspaceB, $this->officer);
    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/orders/{$order->uuid}/reject", ['reason' => 'المبلغ لا يطابق الإيصال.'])
        ->assertOk();

    expect($order->refresh()->status)->toBe('rejected')
        ->and($order->rejection_reason)->toBe('المبلغ لا يطابق الإيصال.')
        ->and(creditsOn($this->student, $this->courseA))->toBe(0);
});

it('shows the officer credit orders from every workspace on the panel list', function (): void {
    /*
    | ⚠️ THE FIFTH LAYER, AND THE ONE THAT LOOKS LIKE NOTHING IS WRONG. The kind
    | cut was already permission-gated; the workspace was not. An officer with a
    | workspace of their own saw a short list and no error — a quiet week rather
    | than a wall.
    */
    grant();
    grant(course: $this->courseB);

    $this->actingAs($this->officer);
    app(WorkspaceContext::class)->set($this->workspaceB);

    $rows = OrderResource::getEloquentQuery()->get();

    expect($rows)->toHaveCount(2);

    // And the tenant owner still sees neither: a credit purchase is the
    // platform's sale, whatever workspace they are standing in.
    $this->actingAs($this->teacherA);
    app(WorkspaceContext::class)->set($this->workspaceA);

    expect(OrderResource::getEloquentQuery()->get())->toHaveCount(0);
});

it('prices the grant on the screen before anything is saved', function (): void {
    /*
    | ⚠️ «المبلغ المتوقَّع» يُقرأُ من حالةِ النموذجِ الحيّة، لا من صفٍّ محفوظ.
    | فإن لم تصلْه الحالةُ بقيَ على جملةِ البداية «اختر الطالب والكورس والباقة»
    | مهما اختارَ الموظّف — ولا شيءَ في الشاشةِ يقولُ لماذا. وهو الرقمُ الذي
    | يُطابَقُ بالإيصالِ في اليدِ قبلَ الحفظِ (FR-006).
    */
    $this->actingAs($this->officer);

    Livewire::test(GrantCreditSubscription::class)
        ->fillForm([
            'student' => $this->student->getKey(),
            'course' => $this->courseA->getKey(),
            'package' => $this->package->getKey(),
        ])
        ->assertDontSee('اختر الطالب')
        ->assertSee('QAR');
});

it('names the field still missing rather than listing all three', function (): void {
    /*
    | ⚠️ جملةٌ تعدُّ الثلاثةَ بعدَ اختيارِ اثنَينِ منها تُقرأُ على أنّها عطل: الموظّفُ
    | يرى ما اختارَه أمامَه ويرى الشاشةَ تطلبُه ثانيةً، فيستنتجُ أنّ الاختيارَ لم
    | يُسجَّل. والناقصُ واحدٌ معروفٌ للشاشةِ بالضبط — فلتقلْه.
    */
    $this->actingAs($this->officer);

    Livewire::test(GrantCreditSubscription::class)
        ->fillForm([
            'course' => $this->courseA->getKey(),
            'package' => $this->package->getKey(),
        ])
        ->assertSee('اختر الطالب.')
        ->assertDontSee('اختر الطالب والكورس والباقة.');
});

it('initialises the form state so the browser has something to bind to', function (): void {
    /*
    | ⛔ العطلُ الذي وجدَه المتصفّحُ وحدَه، ولا يراه اختبارُ Livewire إلّا هكذا.
    |
    | الصفحةُ لم يكن فيها `mount()` إطلاقاً، و`$data` يبدأُ `[]` — فلا مفتاحَ
    | لأيِّ حقل. وحقولُ Filament المعقّدةُ (المنتقي المبحوثُ ورفعُ الملفّ) تربطُ
    | نفسَها بـ`$wire.entangle('data.student')`، و**entangle يشترطُ وجودَ
    | الخاصّيّةِ سلفاً**. فكانت الصفحةُ ترمي في الطرفيّةِ ثلاثةَ أخطاء:
    |
    |   Livewire Entangle Error: Livewire property ['data.student'] cannot be
    |   found on component ...  (وكذلك data.course و data.receipt)
    |
    | فالطالبُ والكورسُ يظهرانِ مختارَينِ على الشاشةِ — Choices.js يرسمُهما في
    | المتصفّح — **ولا تصلُ قيمتُهما الخادمَ أبداً**. قِيسَ على الإنتاج:
    | `data` كانت `{"package":"2"}` وحدَها بعدَ اختيارِ الثلاثة. فليست الرسالةُ
    | هي العاطلة: الشاشةُ كلُّها لم تكن تعملُ — والإيصالُ لا يُرفَعُ كذلك.
    |
    | ⚠️ و`fillForm()` في اختبارِ Livewire يكتبُ الحالةَ عبرَ المخطَّطِ مباشرةً،
    | فيتخطّى مسارَ الربطِ في المتصفّحِ كلَّه — ولذلك مرَّ اختبارُ التسعيرِ أعلاه
    | أخضرَ فوقَ شاشةٍ لا تعملُ إطلاقاً. التوكيدُ هنا على **وجودِ المفاتيح**.
    */
    $this->actingAs($this->officer);

    expect(Livewire::test(GrantCreditSubscription::class)->get('data'))
        ->toHaveKeys(['student', 'course', 'package', 'receipt']);
});
