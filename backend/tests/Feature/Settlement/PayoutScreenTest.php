<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Filament\Pages\RecordTeacherPayouts;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeacherPayout;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| صرفُ مستحقّاتِ المدرّس — البابُ الذي لم يكنْ له زرّ.
|
| ⛔ الفتراتُ تُغلَقُ وحدَها، و`POST /admin/settlement/periods/{period}/payouts`
| قائمٌ منذُ ٠١٤ ولا ينادِيه شيءٌ في الواجهةِ ولا في اللوحة.
|
| ⚠️ **الموظّفُ يملكُ ورشةً أخرى**، وهذا السطرُ هو ما يكشفُ عيبَ ٠٢٤ هنا: كتابةُ
| `status = paid` في `RecordTeacherPayout` كانت منطوقةً بورشةِ الموظّف، فتُصيبُ
| صفرَ صفوف — المالُ خرجَ والفترةُ تقرأُ «مغلقة». وإشعارُ الصرفِ كانَ يقرأُ
| `$payout->teacherProfile` منطوقاً فيسقطُ بلا كلمة. تجهيزةٌ بورشةٍ واحدةٍ تمرُّ
| خضراءَ فوقَ الاثنين.
|
| ⚠️ **سوبر أدمن لا موظّفٌ ماليّ**: `settlement.payout.execute` ليست في صفِّ
| `finance-admin` في `RolePermissionMatrix` — تصلُ إلى السوبر أدمنِ وحدَه.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الصرف']);
    [$this->officerWorkspace] = $this->createWorkspaceWithOwner(['name' => 'ورشة الموظف']);

    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $startsOn = CarbonImmutable::now()->subDays(40)->startOfDay();

    $this->period = SettlementPeriod::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Closed,
        'units_count' => 3,
        'gross_minor' => 15_000,
        'deductions_minor' => 0,
        'net_minor' => 15_000,
        'currency' => 'QAR',
        'closed_at' => now()->subDays(5),
    ]);
});

function payoutScreenFreshPeriod(SettlementPeriod $period): SettlementPeriod
{
    return SettlementPeriod::query()->withoutWorkspaceScope()->findOrFail($period->getKey());
}

it('is a screen at all, not a file nobody discovers', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(RecordTeacherPayouts::class);
});

it('lists a closed period from ANOTHER workspace, with the teacher named', function (): void {
    Livewire::actingAs($this->officer)
        ->test(RecordTeacherPayouts::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->period])
        // ⚠️ الاسمُ لا وجودُ الصفّ: تحميلٌ مسبقٌ منطوقٌ يطبعُ «—» بصمت.
        ->assertTableColumnStateSet('teacher', $this->teacher->name, $this->period);
});

it('refuses the screen to somebody without the platform permission', function (): void {
    $this->actingAs($this->teacher);

    expect(RecordTeacherPayouts::canAccess())->toBeFalse();
});

it('records the payout, marks the period paid, writes the ledger and tells the teacher', function (): void {
    Livewire::actingAs($this->officer)
        ->test(RecordTeacherPayouts::class)
        ->callTableAction('pay', $this->period, ['reference' => 'TRF-777', 'method' => 'تحويل بنكي'])
        ->assertHasNoTableActionErrors();

    $payout = TeacherPayout::query()->withoutWorkspaceScope()->sole();

    expect($payout->amount_minor)->toBe(15_000)
        ->and($payout->reference)->toBe('TRF-777')
        ->and($payout->executed_by)->toBe($this->officer->getKey())
        // ⚠️ الحالةُ هي ما كانَ يسقطُ صامتاً تحتَ نطاقِ ورشةِ الموظّف.
        ->and(payoutScreenFreshPeriod($this->period)->status)->toBe(SettlementPeriodStatus::Paid);

    $entry = LedgerEntry::query()->withoutWorkspaceScope()
        ->where('type', LedgerEntryType::Payout)
        ->sole();

    expect($entry->amount_minor)->toBe(-15_000);

    $sent = Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', NotificationType::TeacherPayoutIssued->value)
        ->first();

    expect($sent)->not->toBeNull()
        ->and($sent->body)->toContain('TRF-777');
});

it('answers a second press with a sentence and pays nothing twice', function (): void {
    $screen = Livewire::actingAs($this->officer)->test(RecordTeacherPayouts::class);

    $screen->callTableAction('pay', $this->period, ['reference' => 'TRF-1']);

    // الصفُّ يغادرُ القائمةَ بعد الصرف، فالضغطةُ الثانيةُ من شاشةٍ ثانيةٍ تنادي
    // الإجراءَ على الفترةِ نفسِها مباشرةً — وهو ما يصلُه الـAPI أيضاً.
    expect(fn () => app(RecordTeacherPayout::class)
        ->handle(payoutScreenFreshPeriod($this->period), $this->officer, 'TRF-2'))
        ->not->toThrow(Throwable::class);

    expect(TeacherPayout::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(LedgerEntry::query()->withoutWorkspaceScope()->where('type', LedgerEntryType::Payout)->count())->toBe(1);
});

it('offers no button on a period whose net is carried rather than paid', function (): void {
    $this->period->forceFill(['net_minor' => -3_000, 'carried_out_minor' => -3_000])->save();

    Livewire::actingAs($this->officer)
        ->test(RecordTeacherPayouts::class)
        ->assertTableActionHidden('pay', $this->period);
});

it('refuses an officer past their two-factor deadline and writes nothing', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    Livewire::actingAs($this->officer->refresh())
        ->test(RecordTeacherPayouts::class)
        ->callTableAction('pay', $this->period, ['reference' => 'TRF-9']);

    expect(TeacherPayout::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(payoutScreenFreshPeriod($this->period)->status)->toBe(SettlementPeriodStatus::Closed);
});
