<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Filament\Pages\ReviewRateRequests;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| ٠٠٦ · T097 — البابُ الذي لم يكنْ له زرّ.
|
| ⛔ المدرّسُ كانَ يطلبُ ولا أحدَ يستطيعُ أن يُقرِّر. `POST /settlement/rate-requests`
| قائمٌ منذُ ٠١٤ و٠٢٧ · T083 بنى له استمارة؛ أمّا بابُ القرارِ
| `‎/admin/settlement/rate-requests/{id}/approve` فقائمٌ منذُ ٠١٤ أيضاً **ولا
| ينادِيه ملفٌّ في `frontend/src` ولا شاشةٌ في اللوحة** — قِيسَ ٢٠٢٦-٠٩-٢١.
| عائلةُ `writeBans.lift` المسجَّلةُ في `CLAUDE.md`، ونصفُها الأوّلُ شُحِنَ فصارَ
| البابُ مفتوحاً بلا مخرج.
|
| ⚠️ **والحالةُ الأولى هنا تقيسُ الاكتشافَ لا المنطق**، وهي التي تلزمُ فعلاً:
| `discoverPages()` على مجلَّدٍ غيرِ مذكورٍ لا يُنتِجُ خطأً ولا مساراً، فصفحةٌ
| سليمةٌ تماماً تبقى ملفّاً لا شاشة — وقد وقعَ ذلك في هذا المستودَعِ ثلاثَ مرّاتٍ
| والتحذيرُ مكتوبٌ فوقَ السطرِ ثلاثَ مرّات.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية سامي']);

    /*
    | ⚠️ الموظّفُ يملكُ ورشةً **أخرى**، وهذا السطرُ وحدَه هو ما يكشفُ العطبَ
    | الخماسيَّ الذي وجدَه ٠٢٤: `WorkspaceContext::id()` ترتدُّ إلى
    | `users.last_workspace_id`، فطابورٌ منطوقٌ يعرضُ ورشةَ الموظّفِ وحدَها بلا
    | خطأٍ ولا رسالة. وتجهيزةٌ بورشةٍ واحدةٍ تمرُّ خضراءَ فوقَه كاملاً.
    */
    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    /*
    | ⛔ سوبر أدمن، لا موظَّفٌ ماليّ — وهذا مقيسٌ لا مفترَض.
    |
    | `settlement.rate.approve` يحملُها **`super-admin` وحدَه** بين أدوارِ المنصّةِ
    | الثلاثة (قِيسَ ٢٠٢٦-٠٩-٢١: finance-admin ⇐ false · compliance-officer ⇐
    | false)، وهو ما يقولُه `RolePermissionMatrix` بنصِّه: «تصلُ إلى السوبر أدمن
    | عبرَ $all». وتجهيزةٌ بموظَّفٍ ماليٍّ تُخرِجُ الصفحةَ فارغةً وتُقرَأُ عطباً في
    | الشاشة، وهي في الحقيقةِ الشاشةُ ترفُضُ من لا يملك.
    |
    | ⚠️ وما زالَ يملكُ ورشةً: `WorkspaceContext::id()` ترتدُّ إلى
    | `users.last_workspace_id` للسوبر أدمن أيضاً، وهو الشرطُ الحاملُ الذي كشفَ
    | العطبَ الخماسيَّ في ٠٢٤ — وبلا سطرِه يمرُّ الطابورُ المنطوقُ أخضرَ.
    */
    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $this->request = RateChangeRequest::query()->forceCreate([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'uuid' => (string) Str::uuid(),
        'teacher_profile_id' => $this->profile->getKey(),
        'session_type' => 'group',
        'current_amount_minor' => 5_000,
        'requested_amount_minor' => 7_500,
        'currency' => 'QAR',
        'status' => RateRequestStatus::Pending->value,
        'requested_by' => $this->teacher->getKey(),
        'requested_at' => now(),
    ]);
});

it('is a screen at all, not a file nobody discovers', function (): void {
    /*
    | ⛔ هذه الحالةُ هي التي تفشلُ حينَ يُنسى سطرُ `discoverPages` — ولا شيءَ
    | آخرَ يفشل. `canAccess()` تُجيبُ `true` والصلاحيّةُ مُنفَّذةٌ والصفحةُ سليمةٌ،
    | وليسَ لها مسارٌ ولا مدخلٌ في القائمة.
    */
    expect(class_exists(ReviewRateRequests::class))->toBeTrue()
        ->and(Filament::getPanel('admin')->getPages())->toContain(ReviewRateRequests::class);
});

it('shows a pending request raised in ANOTHER workspace', function (): void {
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->assertCanSeeTableRecords([$this->request]);
});

it('refuses the screen to somebody without the platform permission', function (): void {
    // المدرّسُ صاحبُ الطلبِ نفسِه: يملكُ ورشةً ولا يملكُ قرارَ سعرِها.
    expect(ReviewRateRequests::canAccess())->toBeFalse();

    $this->actingAs($this->teacher);

    expect(ReviewRateRequests::canAccess())->toBeFalse();
});

it('writes a live rate when the officer approves', function (): void {
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->callTableAction('approve', $this->request);

    /*
    | ⚠️ التوكيدُ على **الصفِّ المكتوب** لا على اختفاءِ السطرِ من الطابور: طابورٌ
    | يفرغُ لأنّ الحالةَ تغيّرَت يُقرَأُ نجاحاً وإن لم يُكتَبْ سعرٌ إطلاقاً — و«لا
    | سعرَ بلا اعتماد» خاصّيّةُ شيفرةٍ لأنّ الاعتمادَ هو الكاتبُ الوحيدُ لهذا الجدول.
    */
    expect(SettlementRate::query()->withoutWorkspaceScope()
        ->where('teacher_profile_id', $this->profile->getKey())
        ->where('amount_minor', 7_500)
        ->exists())->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(RateRequestStatus::Approved);
});

it('refuses a rejection with no reason, in the Action’s own words', function (): void {
    /*
    | ⚠️ السببُ مطلوبٌ في `DecideRateChange` نفسِه، والمدرّسُ يقرؤُه. فحقلٌ
    | اختياريٌّ في الشاشةِ يُنتِجُ رفضاً يسقطُ عندَ الإرسال — وهو ما يُقرَأُ عطباً
    | في الشاشةِ لا شرطاً في القاعدة.
    */
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->callTableAction('reject', $this->request, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($this->request->fresh()->status)->toBe(RateRequestStatus::Pending);
});

/*
| ٠٠٦ · T097 — الخبرُ يصلُ المدرّسَ، والورشةُ لا تحجبُه.
|
| ⛔ الحالاتُ الثلاثُ التاليةُ كُتِبَت **حمراءَ أوّلاً** وفشلَت ثلاثتُها:
| `TeacherProfile` تحتَ `BelongsToWorkspace`، وعلاقةُ `teacherProfile()` على
| الطلبِ وعلى السعرِ كانت بلا تجاوزٍ للنطاق. فحينَ يُقرِّرُ موظَّفُ منصّةٍ سياقُه
| ورشةٌ أخرى — وهي حالةُ هذه الشاشةِ بعينِها — تردُّ العلاقةُ `null`:
| `NotifyRateDecision` يخرجُ باكراً فلا يُبلَّغُ المدرّسُ باعتمادِ سعرِه، والعمودُ
| يطبعُ «—» بدلَ اسمِه. **بلا خطأٍ ولا رسالةٍ في الحالتَين.**
|
| ⚠️ وعلى الإنتاجِ اليومَ `last_workspace_id` للسوبر أدمنِ **NULL** (قِيسَ
| ٢٠٢٦-٠٩-٢١)، و`WorkspaceScope` لا تُضيفُ شرطاً حينَ يكونُ السياقُ فارغاً —
| فالفخُّ منصوبٌ ولم يقعْ بعد: أوّلُ مرّةٍ يفتحُ فيها المالكُ ورشةً يقعُ صامتاً.
| وتجهيزةٌ بورشةٍ واحدةٍ لا تراه إطلاقاً، وهو ما مرَّ به `RateEndpointTest`.
*/
it('tells the teacher their rate was approved, from another workspace', function (): void {
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->callTableAction('approve', $this->request);

    $sent = Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', NotificationType::SettlementRateApproved->value)
        ->first();

    // «من متى» هو المقصودُ من الرسالة: رقمٌ جديدٌ بلا تاريخٍ يُقرَأُ كأنّه يسري
    // على ساعاتٍ دُرِّسَت سلفاً.
    expect($sent)->not->toBeNull()
        ->and($sent->body)->toContain('يسري من');
});

/*
| ⛔ `SettlementRateRejected` كانَ **حالةً مُعدَّدةً بقُرّاءٍ بلا كاتب**: صفٌّ في
| `NotificationCategory`، وقالبٌ مبذورٌ **وحيٌّ على الإنتاج** (`id 8` · فعّال ·
| `not_required`) — ولا سطرَ في الشجرةِ كلِّها يُرسِلُه. عائلةُ
| `ClassSessionStatus::Interrupted` المسجَّلةُ في `CLAUDE.md`: متطلَّبٌ ظنَّ
| الجميعُ أنّه مُنفَّذٌ لأنّ كلَّ ما حولَه مكتوب.
*/
it('tells the teacher their rate request was refused, with the reason', function (): void {
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->callTableAction('reject', $this->request, ['reason' => 'السعر أعلى من سقف المادة']);

    $sent = Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', NotificationType::SettlementRateRejected->value)
        ->first();

    // السببُ هو الرسالة. «لا» بلا سببٍ طلبٌ يُعادُ إرسالُه الأسبوعَ القادم،
    // وقاعدةٌ لا يستطيعُ أحدٌ تعلُّمَها — وهو ما يقولُه `DecideRateChange` نفسُه.
    expect($sent)->not->toBeNull()
        ->and($sent->body)->toContain('السعر أعلى من سقف المادة');
});

it('prints the teacher’s name in the queue, not a dash', function (): void {
    /*
    | ⚠️ `assertCanSeeTableRecords` تمرُّ فوقَ هذا كاملاً: تسألُ عن **وجودِ
    | الصفِّ** لا عمّا رُسِمَ فيه. والتجاوزُ على الاستعلامِ الأمِّ لا يصلُ إلى
    | التحميلِ المسبَق — وهي القاعدةُ التي دفعَتها سلسلةُ التدقيقِ في ٠٢٤:
    | «يُعادُ التصريحُ به في كلِّ `with()`».
    */
    Livewire::actingAs($this->officer)
        ->test(ReviewRateRequests::class)
        ->assertTableColumnStateSet('teacher', $this->teacher->name, $this->request);
});
