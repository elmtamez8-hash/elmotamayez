<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Modules\Identity\Actions\UpdateAccountDetails;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\UserStatus;
use Livewire\Livewire;

/**
 * دفترُ الحساباتِ صارَ يُعدَّل — والحدُّ هو ما يجبُ أن يُقاس.
 *
 * الشاشةُ كانت مغلقةً بثلاثةِ أعذارٍ صحيحة: `status` بوّابةُ دخول،
 * `platform_role` يقرِّرُ أيَّ منتَجٍ يُرى، `is_super_admin` صلاحيّةُ المنصّةِ
 * كلِّها. فتحُها لبياناتِ الإنسانِ لا ينقضُ ذلك — **ما لم تتسرّبْ حمولةٌ إلى
 * أحدِها.** والكتابةُ بـ`forceFill`، وهو يتخطّى `$guarded`.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);

    $this->account = User::factory()->create([
        'first_name' => 'سارة',
        'last_name' => 'منصور',
        'email' => 'sara@example.test',
        'email_verified_at' => now(),
        'platform_role' => PlatformRole::Student,
        'status' => UserStatus::PendingGuardianConsent->value,
    ]);

    $this->actingAs($this->admin);
});

it('corrects a name without touching anything the platform decided', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->account->getRouteKey()])
        ->fillForm(['first_name' => 'ساره', 'phone' => '+97455512345'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $this->account->fresh();

    expect($fresh?->first_name)->toBe('ساره')
        ->and($fresh?->phone)->toBe('+97455512345')
        // البريدُ لم يتغيّرْ، فتوثيقُه باقٍ: إسقاطُه على كلِّ حفظٍ يُبطِلُ توثيقَ
        // كلِّ حسابٍ يُصحَّحُ فيه حرفٌ من اسمِه.
        ->and($fresh?->email_verified_at)->not->toBeNull();
});

it('drops the email verification when the address itself changes', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->account->getRouteKey()])
        ->fillForm(['email' => 'sara.mansour@example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    /*
    | ⚠️ `email_verified_at` جوابٌ عن **العنوانِ الذي وُثِّق**. نقلُه إلى عنوانٍ
    | جديدٍ يقولُ إنّ إنساناً أثبتَ ملكيّةَ بريدٍ لم يُرسَلْ إليه شيءٌ قطّ — وعلى
    | هذا الإنتاجِ بالذات، حيثُ `MAIL_MAILER=log`، لن يُرسَلَ إليه شيءٌ أبداً.
    */
    expect($this->account->fresh()?->email_verified_at)->toBeNull();
});

it('keeps the same verification when only the letter case changes', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->account->getRouteKey()])
        ->fillForm(['email' => 'Sara@Example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    // «Sara@Example.test» و«sara@example.test» بريدٌ واحد. إسقاطُ التوثيقِ عليهما
    // عقوبةٌ على حالةِ حرف.
    expect($this->account->fresh()?->email_verified_at)->not->toBeNull();
});

it('refuses to carry a platform decision, measured at the Action', function (): void {
    /*
    | ⚠️ هنا لا في الشاشة، والفرقُ قِيسَ لا فُرِض: توكيدٌ عبرَ Livewire يبقى
    | أخضرَ بعدَ **حذفِ القائمةِ البيضاءِ من الإجراءِ بالكامل**، لأنّ Filament
    | يجرّدُ كلَّ مفتاحٍ لا حقلَ له قبلَ `handleRecordUpdate` — فيقيسُ Filament
    | لا الحارس. والإجراءُ هو البابُ الذي تشتركُ فيه الشاشةُ والبذورُ وأيُّ
    | مسارٍ يُكتَبُ غداً، والكتابةُ فيه بـ`forceFill` الذي يتخطّى `$guarded`.
    */
    app(UpdateAccountDetails::class)->handle($this->account, [
        'first_name' => 'سارة',
        'is_super_admin' => true,
        'platform_role' => PlatformRole::Teacher->value,
        'status' => UserStatus::Active->value,
    ]);

    $fresh = $this->account->fresh();

    expect($fresh?->first_name)->toBe('سارة')
        ->and($fresh?->is_super_admin)->toBeFalse()
        ->and($fresh?->platform_role)->toBe(PlatformRole::Student)
        // ⚠️ الأخطرُ في الثلاثة: هذه الحالةُ تمنعُ الدخولَ، وقلبُها يختلقُ موافقةَ
        // وليِّ أمرٍ لم تحدث.
        ->and($fresh?->status)->toBe(UserStatus::PendingGuardianConsent->value);
});

it('declares no field for any of the three on the screen either', function (): void {
    // الحارسُ الثاني، ومختلفٌ عن الأوّل: مخطَّطُ النموذجِ هو ما يجرّدُ حمولةَ
    // Livewire. حقلٌ يُضافُ هنا غداً يفتحُ البابَ من فوقِ الإجراءِ لا من خلالِه.
    $fields = array_keys(
        Livewire::test(EditUser::class, ['record' => $this->account->getRouteKey()])
            ->instance()
            ->form
            ->getFlatFields()
    );

    expect($fields)->toBe(['first_name', 'last_name', 'email', 'phone', 'country']);
});

it('links to the account-creation page from the list', function (): void {
    /*
    | سطحٌ بلا رابطٍ واردٍ هو سطحٌ غيرُ موجود: الصفحةُ كانت في القائمةِ الجانبيّةِ
    | وحدَها، فمن فتحَ «المستخدمون» بحثَ عن زرِّ إضافةٍ ولم يجدْه.
    */
    Livewire::test(ListUsers::class)
        ->assertActionVisible('createAccount');
});

it('shows the ledger and its edit button to nobody but a platform administrator', function (): void {
    /*
    | ⚠️ اتّجاهُ الرفضِ هو ما يُقاس. **كلُّ مدرّسٍ يصلُ إلى `/admin`**، وقائمةُ
    | Filament لا تستدعي سياسةَ الصفِّ أبداً — وهو الثمنُ الذي دفعَه هذا المستودعُ
    | مرّةً في `OrderResource`. و`canEdit()` بابٌ ثانٍ يُفتَحُ بكتابةِ العنوانِ لا
    | بالنقرِ على زرّ، فيُقاسُ وحدَه.
    */
    $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Teacher]));

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(UserResource::canEdit($this->account))->toBeFalse();
});
