<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages\EditTeacherProfile;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages\ViewTeacherProfile;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Livewire\Livewire;

/**
 * لوحةُ الإدارةِ لم يكن فيها زرُّ اعتمادٍ واحدٌ يعملُ لهذه الصفوف.
 *
 * ⚠️ قِيسَ على الإنتاجِ (2026-09-04): أربعةُ ملفّاتٍ وطلبانِ اثنان — طلبٌ مسوّدةٌ
 * وطلبٌ معتمَد. فالملفّانِ «قيد المراجعة» لا طلبَ لهما إطلاقاً، وطابورُ الطلباتِ
 * — البابُ الوحيدُ للاعتمادِ قبلَ هذا التغيير — كان خالياً ممّا يُبَتُّ فيه. ولا
 * يستطيعُ المنتَجُ إنتاجَ تلك الحالة: {@see SubmitTeacherApplication} وحدَها تكتبُ
 * `pending` وهي تربطُ الطلبَ بالملفِّ في السطرِ نفسِه — فهما من البذور.
 */
beforeEach(function (): void {
    $this->officer = User::factory()->create(['is_super_admin' => true]);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(
        ['participates_in_marketplace' => false],
        ['platform_role' => PlatformRole::Teacher],
    );

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    $this->actingAs($this->officer);
});

it('approves a profile that no application was ever filed for', function (): void {
    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->callAction('approve');

    /*
    | ⚠️ إعادةُ الجلبِ لا `$this->profile`: العمودانِ يُكتَبانِ بـ`forceFill` داخلَ
    | الـAction على نسخةٍ أخرى من الصفّ، والنسخةُ التي في يدِ الاختبارِ لا تعلمُ
    | بذلك — توكيدٌ عليها يمرُّ خضراءَ فوقَ قاعدةٍ لم تتغيّرْ ويفشلُ فوقَ قاعدةٍ
    | تغيّرت، أيُّهما اتّفق.
    */
    expect($this->profile->fresh()?->approval_status)->toBe(TeacherProfile::STATUS_APPROVED);
});

it('routes an approval through the application when one is still open', function (): void {
    $application = TeacherApplication::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'current_step' => 4,
    ]);

    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->callAction('approve');

    /*
    | ⚠️ الطلبُ هو التوكيدُ، لا حالةُ الملفّ. زرٌّ ينادي `ReinstateTeacher` دائماً
    | يجعلُ الملفَّ «معتمَداً» تماماً كما هنا — ويتركُ الطلبَ `submitted` إلى
    | الأبد، بلا مراجِعٍ مسجَّلٍ وبلا إشعارٍ وبلا ختمِ مشاركةِ المساحة. فتوكيدٌ
    | على `approval_status` وحدَه يمرُّ فوقَ الفرعِ الخطأ.
    */
    expect($application->fresh()?->status)->toBe(TeacherApplication::STATUS_APPROVED)
        ->and($application->fresh()?->reviewed_by)->toBe($this->officer->getKey())
        // FR-026: المشاركةُ تُختَمُ عندَ الاعتمادِ لا عندَ الميلاد، وعلى مساحةِ
        // المدرّسِ نفسِه — وهي التي تجعلُه معروضاً في السوق.
        ->and(Workspace::query()->whereKey($this->workspace->getKey())->value('participates_in_marketplace'))->toBeTruthy()
        ->and($this->profile->fresh()?->is_publicly_listed)->toBeTrue();
});

it('suspends an approved teacher and takes them off the marketplace', function (): void {
    $this->profile->forceFill([
        'approval_status' => TeacherProfile::STATUS_APPROVED,
        'is_publicly_listed' => true,
    ])->save();

    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->callAction('suspend');

    expect($this->profile->fresh()?->approval_status)->toBe(TeacherProfile::STATUS_SUSPENDED)
        ->and($this->profile->fresh()?->is_publicly_listed)->toBeFalse();
});

it('edits the wizard fields and refuses to carry a decision with them', function (): void {
    $subject = Subject::query()->first() ?? Subject::factory()->create();

    Livewire::test(EditTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->fillForm([
            'headline' => 'مدرّسُ رياضيّاتٍ للثانويّة',
            'years_experience' => 9,
            'subjects' => [$subject->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $this->profile->fresh();

    expect($fresh?->headline)->toBe('مدرّسُ رياضيّاتٍ للثانويّة')
        ->and($fresh?->years_experience)->toBe(9)
        ->and($fresh?->subjects()->pluck('subjects.id')->all())->toBe([$subject->getKey()])
        // ⚠️ العمودانِ في `$fillable`. حفظٌ مباشرٌ يكتبُهما بلا صوت، ويتخطّى
        // الاشتقاقَ وختمَ المشاركةِ والإشعار.
        ->and($fresh?->approval_status)->toBe(TeacherProfile::STATUS_PENDING)
        ->and($fresh?->is_publicly_listed)->toBeFalse();
});

it('offers each decision only in the state it belongs to', function (): void {
    /*
    | ⚠️ حارسُ الحالةِ هو ما يمنعُ الزرَّينِ من التناقض. «اعتماد» على ملفٍّ معتمَدٍ
    | يمرُّ على الفرعِ الذي لا طلبَ فيه فيكتبُ ما هو مكتوبٌ ويُبطِلُ ذاكرةَ السوقِ
    | بلا سبب؛ و«إيقاف» على ملفٍّ «قيد المراجعة» يقلبُه «موقوفاً» — حالةٌ تعني
    | «كان معتمَداً فسُحِبَ منه» وهي كذبةٌ عن مدرّسٍ لم يُعتمَدْ قطّ، ويقرؤها
    | الطابورُ وشريطُ المرشِّحاتِ على أنّها عقوبة.
    */
    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionHidden('suspend');

    $this->profile->forceFill(['approval_status' => TeacherProfile::STATUS_APPROVED])->save();

    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->fresh()?->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionVisible('suspend');
});
