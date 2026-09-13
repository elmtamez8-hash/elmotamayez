<?php

declare(strict_types=1);

use App\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Livewire\Livewire;

/*
| مفتاحُ «المكانُ في السوق» لم يكنْ له بابٌ يُضغَط.
|
| ⚠️ الصلاحيّةُ (`marketplace.participation.manage`) والإجراءُ
| (`SetMarketplaceParticipation`) والمسارُ (`PUT /workspace/marketplace-participation`)
| كلُّها موجودةٌ منذُ ٠٢٥ — ولا ملفَّ واحداً تحتَ `frontend/src` يُناديها، ولا شاشةَ
| في اللوحة. فمكانُ عملٍ خارجَ السوقِ يُخفي مدرّسيه وكورساتِه ومقالاتِه **بلا
| وسيلةٍ لأحدٍ أن يُرجِعَه**. قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٣: مكانانِ خارجَ السوقِ
| وفيهما كورسانِ منشورانِ وخمسةُ طلّابٍ مسجّلين.
|
| ⚠️ **والقياسُ على العرضِ العامِّ لا على العمود.** تأكيدٌ على
| `participates_in_marketplace` وحدَه يمرُّ فوقَ زرٍّ يكتبُ العمودَ ولا يُفرِغُ
| الخبء — فالسوقُ يبقى يعرضُ الجوابَ القديم، أي زرٌّ «نجح» ولا شيءَ يتغيّرُ على
| الصفحة. السؤالُ هو «هل ظهرَ الكورس؟».
*/

beforeEach(function (): void {
    $this->officer = User::factory()->create(['is_super_admin' => true]);

    /*
    | ⚠️ مساحةٌ للمراجِعِ نفسِه: `WorkspaceContext::id()` يرتدُّ إلى
    | `last_workspace_id` حتّى للمشرِفِ العامّ، وبلا هذا السطرِ يبقى السياقُ `null`
    | و`WorkspaceScope` لا يضيفُ شرطاً — فتمرُّ الحالاتُ على بناءٍ مكسور. نفسُ
    | الدرسِ الذي كشفَ خمسَ طبقاتٍ في سلسلةِ اعتمادِ المدفوعات.
    */
    [$decoy] = $this->createWorkspaceWithOwner([], ['platform_role' => PlatformRole::Teacher]);
    $this->officer->forceFill(['last_workspace_id' => $decoy->getKey()])->save();

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(
        ['participates_in_marketplace' => false],
        ['platform_role' => PlatformRole::Teacher],
    );

    TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'approval_status' => TeacherProfile::STATUS_APPROVED,
        // مشتقٌّ من (معتمَد × المكانُ مشارِك)، والمكانُ خارجَ السوقِ هنا.
        'is_publicly_listed' => false,
    ]);

    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->teacher->getKey(),
        'status' => 'published',
        'visibility' => 'public',
    ]);
});

function listedCourseIds(): array
{
    return Course::query()->publiclyListed()->pluck('courses.id')->all();
}

it('puts a withdrawn workspace back on the marketplace, course and all', function (): void {
    // البرهانُ على أنّ الحالةَ الابتدائيّةَ هي العطبُ نفسُه، لا فِخاخَ فيها.
    expect(listedCourseIds())->not->toContain($this->course->id);

    $this->actingAs($this->officer);

    Livewire::test(ListWorkspaces::class)
        ->callTableAction('participation', $this->workspace->getKey());

    expect($this->workspace->refresh()->participates_in_marketplace)->toBeTrue()
        ->and(listedCourseIds())->toContain($this->course->id);
});

it('withdraws a workspace without touching any teacher approval', function (): void {
    $this->workspace->forceFill(['participates_in_marketplace' => true])->save();
    TeacherProfile::query()->where('workspace_id', $this->workspace->getKey())
        ->update(['is_publicly_listed' => true]);

    $this->actingAs($this->officer);

    Livewire::test(ListWorkspaces::class)
        ->callTableAction('participation', $this->workspace->getKey());

    expect($this->workspace->refresh()->participates_in_marketplace)->toBeFalse()
        ->and(listedCourseIds())->not->toContain($this->course->id);

    /*
    | ⚠️ الانسحابُ قرارُ المكانِ عن موضعِ إعلانِه، لا حكمٌ على من فيه — ولو مسَّ
    | `approval_status` لصارَ الرجوعُ طابورَ اعتمادٍ من جديدٍ لكلِّ مدرّس.
    */
    expect(TeacherProfile::query()->where('workspace_id', $this->workspace->getKey())
        ->value('approval_status'))->toBe(TeacherProfile::STATUS_APPROVED);
});

it('never publishes a teacher who was never approved', function (): void {
    /*
    | ⚠️ الاتّجاهُ الخطِرُ في إصلاحِ الاشتقاق. الإجراءُ يُعيدُ حسابَ
    | `is_publicly_listed` لمدرّسي المكان، وتحديثٌ بلا شرطِ `approved` ينشرُ
    | مدرّساً معلّقاً — أو موقوفاً بقرارِ إدارة — بضغطةِ زرٍّ عن **مكانِ العمل**،
    | وهو بابٌ جانبيٌّ حولَ طابورِ الاعتمادِ كلِّه.
    */
    $pending = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    $this->actingAs($this->officer);

    Livewire::test(ListWorkspaces::class)
        ->callTableAction('participation', $this->workspace->getKey());

    expect($this->workspace->refresh()->participates_in_marketplace)->toBeTrue()
        ->and($pending->refresh()->is_publicly_listed)->toBeFalse()
        ->and($pending->approval_status)->toBe(TeacherProfile::STATUS_PENDING);
});

it('keeps the screen shut to anyone who is not platform staff', function (): void {
    // ⚠️ الاتّجاهُ الآخرُ ضروريّ: شاشةٌ تُدرِجُ مكانَ عملٍ في سوقٍ عامٍّ بضغطةٍ
    // واحدةٍ هي قرارُ منصّةٍ لا قرارُ مستأجر.
    $this->actingAs($this->teacher);

    Livewire::test(ListWorkspaces::class)->assertForbidden();
});
