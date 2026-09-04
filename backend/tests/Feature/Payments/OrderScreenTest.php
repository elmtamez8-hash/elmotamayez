<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\RelationManagers\TransactionsRelationManager;
use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * شاشةُ الطلبِ — عطلانِ قِيسا على الإنتاج 2026-09-04.
 *
 * ⛔ الأوّل: «حدث خطأ أثناء تحميل الصفحة». إغلاقانِ في
 * {@see TransactionsRelationManager} يشترطانِ `string $state`، و
 * `PaymentTransaction::$status` **مصبوبٌ إلى `PaymentStatus`** — فيصلُ كائنَ enum
 * ويرمي PHP `TypeError` تسقطُ معه الصفحةُ كلُّها.
 *
 * ⚠️ **ولا يظهرُ إلّا على طلبٍ له حركةُ دفعٍ واحدةٌ على الأقلّ**: جدولٌ فارغٌ لا
 * يُنفِّذُ مُنسِّقَ عمود. على الإنتاجِ سقطَ الطلبُ ٥ (حركةٌ واحدة) وفتحَ الطلبُ ٧
 * (بلا حركات) — فبدا العطلُ متقطّعاً وهو حتميّ. تجهيزةٌ بلا حركةٍ تمرُّ خضراءَ
 * فوقَ الشيفرةِ العاطلةِ تماماً.
 *
 * ⛔ والثاني: الإيصالُ المرفوعُ لا يظهر. لا كلمةَ `receipt` كانت في
 * `OrderResource` كلِّه، بينما تعليقُ المسارِ يقولُ إنّ هذا المورِدَ «هو من يسكُّ
 * التوقيع». فالموظّفُ يعتمدُ على بياض — وهو ما وُجِدَت خطوةُ الاعتمادِ لتمنعَه.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    /*
    | ⚠️ للمراجِعِ مساحتُه، وهي ما يُسلّحُ التجهيزة: `WorkspaceContext::id()` يرتدُّ
    | إلى `last_workspace_id` حتّى لموظّفِ المنصّة، وقراءةٌ منطاقةٌ تعرضُ لا شيءَ
    | وتمرُّ خضراءَ في تجهيزةٍ بمساحةٍ واحدة (٠٢٤).
    */
    [$decoy] = $this->createWorkspaceWithOwner();
    $this->officer->forceFill(['last_workspace_id' => $decoy->getKey()])->save();

    $this->student = User::factory()->create();

    $this->order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'amount_minor' => 48000,
        'currency' => 'QAR',
        'status' => 'pending',
    ]);

    $this->actingAs($this->officer);
});

it('opens the order screen when a payment transaction exists', function (): void {
    PaymentTransaction::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 48000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Pending,
        'reference' => 'TRX-TEST-1',
    ]);

    /*
    | ⚠️ التوكيدُ على **جدولِ العلاقةِ نفسِه**، لا على الصفحةِ وحدَها: مُنسِّقُ
    | العمودِ لا يُنفَّذُ إلّا عندَ تصييرِ صفّ. صفحةٌ تُفتَحُ وحدَها تمرُّ فوقَ
    | الإغلاقِ العاطلِ بلا أن تلمسَه.
    */
    Livewire::test(TransactionsRelationManager::class, [
        'ownerRecord' => $this->order,
        'pageClass' => EditOrder::class,
    ])
        // ⚠️ `loadTable()`: جدولُ Filament مؤجَّلُ التحميل، فبدونِه يبقى فارغاً
        // ولا يُنفَّذُ مُنسِّقُ عمودٍ واحد — أي أنّ التوكيدَ يمرُّ فوقَ العطلِ نفسِه.
        ->loadTable()
        ->assertSuccessful()
        ->assertCanSeeTableRecords(PaymentTransaction::query()->withoutGlobalScopes()->get());
});

it('shows the uploaded receipt on the screen where the decision is made', function (): void {
    Storage::fake('local');

    $this->order->addMedia(UploadedFile::fake()->image('receipt.jpg'))->toMediaCollection('receipt');

    /*
    | ⚠️ التوكيدُ على المسارِ الموقَّعِ لا على الكلمة: رابطٌ بلا توقيعٍ يُرفَضُ عندَ
    | البابِ (`middleware('signed')`)، فيقرأُ الموظّفُ «صفحةٌ غيرُ موجودة» عن
    | إيصالٍ موجودٍ على القرص.
    */
    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('receipt.jpg')
        ->assertSee('signature=', escape: false);
});

it('says so plainly when there is no receipt at all', function (): void {
    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('لا إيصالَ على هذا الطلب.');
});

/**
 * ⛔ البابُ الثاني للقرار — قِيسَ على الإنتاج 2026-09-04.
 *
 * حقلُ `status` في النموذجِ كان `Select` قابلاً للكتابة، فحفظُ الصفحةِ يكتبُ
 * `approved` عبرَ `handleRecordUpdate` ويتخطّى {@see ApproveOrder} بأكملِه: لا
 * `PaymentApproved`، فلا تسجيلَ ولا سكَّ أرصدةٍ ولا حركةَ دفعٍ ولا `approved_by`
 * ولا صفَّ تدقيقٍ ولا إشعار. الطلبُ ٧ على الإنتاج (‏٤٨٠ ر.ق · أربعةُ أرصدة) وقعَ
 * فيه: «معتمَد» بلا معتمِدٍ ولا وقتٍ ولا حركة، والطالبُ برصيدٍ صفر.
 */
it('refuses to write the status from the form, whatever the payload says', function (): void {
    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        // ⚠️ `set` على الحمولةِ لا `fillForm`: النائبُ ليس حقلاً يُملأ، والسؤالُ
        // هو ما يفعلُه الحفظُ بمفتاحٍ وصلَ من المتصفّح.
        ->set('data.status', 'approved')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->order->fresh()?->status)->toBe('pending')
        ->and(PaymentTransaction::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('offers the decision on the screen that shows the receipt', function (): void {
    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionVisible('reject');
});

/**
 * ⚠️ الشرطُ `isPending()` لا `status === 'pending'`.
 *
 * {@see UploadPaymentReceipt} يختمُ `under_review`، ومطالبةُ `ApproveOrder` تقبلُه
 * — فالهجاءُ الثاني كان يُخفي الزرَّينِ عن الطلباتِ التي لها إيصال، أي عن الطلبِ
 * الذي يُقرَّرُ فيه بالضبط. وهو ما دفعَ الموظّفَ إلى الحقلِ أصلاً.
 */
it('keeps the buttons on an order whose receipt moved it to under review', function (): void {
    $this->order->forceFill(['status' => 'under_review'])->save();

    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionVisible('reject');
});

it('mints the transaction and the audit stamp when the button is the door', function (): void {
    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->callAction('approve')
        ->assertHasNoActionErrors();

    $order = $this->order->fresh();

    expect($order?->status)->toBe('approved')
        // ⚠️ الثلاثةُ معاً: هي ما يميّزُ قراراً حقيقيّاً عن كتبٍ من النموذج.
        ->and($order?->approved_by)->toBe($this->officer->getKey())
        ->and($order?->approved_at)->not->toBeNull();

    $transaction = PaymentTransaction::query()->withoutGlobalScopes()->firstOrFail();

    expect($transaction->captured_order_id)->toBe($this->order->getKey())
        ->and($transaction->status)->toBe(PaymentStatus::Captured);
});
