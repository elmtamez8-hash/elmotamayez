<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Filament\Pages\ReverseTeachingUnits;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| تصحيحُ وحدةِ التدريسِ من اللوحة — `POST /admin/settlement/units/{unit}/reverse`
| قائمٌ منذُ ٠١٤ بلا زرّ.
|
| ⚠️ **الموظّفُ يملكُ ورشةً أخرى** — والتجهيزةُ تُثبِتُ ذلك قبلَ أن تسأل: السياقُ
| يُعادُ بناؤه بعد التجهيزِ ويُفحَصُ أنّه ورشةُ الموظّف، وإلّا لكانَ اختبارُ «يرى
| وحدةَ ورشةٍ أخرى» أخضرَ على سياقٍ مجمَّدٍ على null لا يُطبِّقُ نطاقاً أصلاً.
|
| ⚠️ **الضغطةُ الثانيةُ جوابٌ لا 500.** الوحدةُ الأصليّةُ لا تُمَسّ فحالتُها تبقى
| `accrued`، والعكسُ الثاني كانَ يصطدمُ بـ`teaching_units_seat_unique`.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية التصحيح']);
    [$this->officerWorkspace] = $this->createWorkspaceWithOwner(['name' => 'ورشة الموظف']);

    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    $this->student = User::factory()->create(['first_name' => 'طالب', 'last_name' => 'الوحدة']);

    $this->unit = app(WorkspaceContext::class)->forWorkspace($this->teacherWorkspace, function () {
        $profile = TeacherProfile::factory()->create([
            'workspace_id' => $this->teacherWorkspace->getKey(),
            'user_id' => $this->teacher->getKey(),
        ]);

        $unit = TeachingUnit::factory()->create([
            'workspace_id' => $this->teacherWorkspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'student_user_id' => $this->student->getKey(),
            'class_session_id' => ClassSession::factory()->create(['workspace_id' => $this->teacherWorkspace->getKey()])->getKey(),
            'amount_minor' => 6_000,
            'delivered_at' => CarbonImmutable::now()->subDays(3),
        ]);

        // As the accrual listener leaves it: the unit and its ledger line.
        LedgerEntry::factory()->create([
            'workspace_id' => $this->teacherWorkspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'teaching_unit_id' => $unit->getKey(),
            'amount_minor' => 6_000,
        ]);

        return $unit;
    });

    app()->forgetInstance(WorkspaceContext::class);
});

/** Signs the officer in and proves the context is THEIR workspace, not null. */
function reverseScreenAs(User $officer): void
{
    test()->actingAs($officer);
    app()->forgetInstance(WorkspaceContext::class);

    expect(app(WorkspaceContext::class)->id())->toBe((int) $officer->last_workspace_id);
}

function reverseScreenReversals(TeachingUnit $unit): int
{
    return TeachingUnit::query()->withoutWorkspaceScope()->where('reversal_of_id', $unit->getKey())->count();
}

function reverseScreenLedgerReversals(): int
{
    return LedgerEntry::query()->withoutWorkspaceScope()->where('type', LedgerEntryType::Reversal)->count();
}

it('is a screen at all, not a file nobody discovers', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(ReverseTeachingUnits::class);
});

it('lists a unit from ANOTHER workspace, with the teacher and the student named', function (): void {
    reverseScreenAs($this->officer);

    Livewire::test(ReverseTeachingUnits::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->unit])
        // ⚠️ الاسمُ لا وجودُ الصفّ: تحميلٌ مسبقٌ منطوقٌ يطبعُ «—» بصمت.
        ->assertTableColumnStateSet('teacher', $this->teacher->name, $this->unit)
        ->assertTableColumnStateSet('student_name', 'طالب الوحدة', $this->unit)
        ->assertTableActionVisible('reverse', $this->unit);
});

it('reverses once: a negative unit with author and reason, one ledger line, the original untouched', function (): void {
    reverseScreenAs($this->officer);

    Livewire::test(ReverseTeachingUnits::class)
        ->callTableAction('reverse', $this->unit, ['reason' => 'نزاع حُسم لصالح الطالب'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('صُحِّحت الوحدة وسُجِّل القيد العكسي');

    $reversal = TeachingUnit::query()->withoutWorkspaceScope()->where('reversal_of_id', $this->unit->getKey())->sole();

    expect($reversal->amount_minor)->toBe(-6_000)
        ->and($reversal->reversal_reason)->toBe('نزاع حُسم لصالح الطالب')
        ->and($reversal->reversed_by)->toBe($this->officer->getKey())
        // The unit's workspace, never the officer's.
        ->and((int) $reversal->workspace_id)->toBe((int) $this->teacherWorkspace->getKey())
        ->and(reverseScreenLedgerReversals())->toBe(1);

    $original = TeachingUnit::query()->withoutWorkspaceScope()->findOrFail($this->unit->getKey());

    expect($original->amount_minor)->toBe(6_000)
        ->and($original->status)->toBe(TeachingUnitStatus::Accrued);
});

it('answers a second press with a sentence, and writes nothing twice', function (): void {
    reverseScreenAs($this->officer);

    $screen = Livewire::test(ReverseTeachingUnits::class);
    $screen->callTableAction('reverse', $this->unit, ['reason' => 'التصحيح الأول']);

    // The original stays on the list, so the button must not be offered again.
    Livewire::test(ReverseTeachingUnits::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->unit])
        ->assertTableActionHidden('reverse', $this->unit)
        ->assertTableColumnStateSet('status', 'مستحقّة · مصحَّحة', $this->unit);

    // A second screen opened before the first press — it reaches the Action
    // directly, as the API does. Null, not a unique-index 500.
    $fresh = TeachingUnit::query()->withoutWorkspaceScope()->findOrFail($this->unit->getKey());

    expect(app(ReverseTeachingUnit::class)->handle($fresh, 'التصحيح الثاني', $this->officer))->toBeNull()
        ->and(reverseScreenReversals($this->unit))->toBe(1)
        ->and(reverseScreenLedgerReversals())->toBe(1);
});

it('offers no button on a reversal row', function (): void {
    reverseScreenAs($this->officer);

    Livewire::test(ReverseTeachingUnits::class)
        ->callTableAction('reverse', $this->unit, ['reason' => 'وحدة نشأت خطأً']);

    $reversal = TeachingUnit::query()->withoutWorkspaceScope()->where('reversal_of_id', $this->unit->getKey())->sole();

    Livewire::test(ReverseTeachingUnits::class)
        ->loadTable()
        ->assertTableActionHidden('reverse', $reversal);
});

it('refuses a reason shorter than the API accepts', function (): void {
    reverseScreenAs($this->officer);

    Livewire::test(ReverseTeachingUnits::class)
        ->callTableAction('reverse', $this->unit, ['reason' => 'لا'])
        ->assertHasTableActionErrors(['reason']);

    expect(reverseScreenReversals($this->unit))->toBe(0);
});

it('refuses an officer past their two-factor deadline and writes nothing', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    reverseScreenAs($this->officer->refresh());

    Livewire::test(ReverseTeachingUnits::class)
        ->callTableAction('reverse', $this->unit, ['reason' => 'نزاع حُسم']);

    expect(reverseScreenReversals($this->unit))->toBe(0)
        ->and(reverseScreenLedgerReversals())->toBe(0);
});

/*
| ⚠️ `settlement.period.manage` is not on `finance-admin` in the matrix — the
| officer who records payouts does not correct units. That is the matrix as it
| stands, not a decision this screen takes; granting it is the owner's call.
*/
it('is closed to the finance officer and to a teacher, and the button with it', function (): void {
    $finance = makePlatformStaff(Roles::FINANCE_ADMIN);
    $finance->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    reverseScreenAs($finance);
    expect(ReverseTeachingUnits::canAccess())->toBeFalse();

    Livewire::test(ReverseTeachingUnits::class)->assertForbidden();

    $this->actingAs($this->teacher);
    app()->forgetInstance(WorkspaceContext::class);
    expect(ReverseTeachingUnits::canAccess())->toBeFalse();

    expect(reverseScreenReversals($this->unit))->toBe(0);
});

it('answers a second API reverse with 422, not a unique-index 500', function (): void {
    Sanctum::actingAs($this->officer);

    $unit = $this->unit->uuid;

    // ⚠️ Through the officer's OWN workspace would 404 on the route binding
    // (scoped) — so the API is asked from inside the unit's workspace, which is
    // the path the endpoint has today.
    $this->officer->forceFill(['last_workspace_id' => $this->teacherWorkspace->getKey()])->save();
    app()->forgetInstance(WorkspaceContext::class);

    $this->postJson("/api/v1/admin/settlement/units/{$unit}/reverse", ['reason' => 'التصحيح الأول'])->assertCreated();
    $this->postJson("/api/v1/admin/settlement/units/{$unit}/reverse", ['reason' => 'التصحيح الثاني'])
        ->assertStatus(422)
        ->assertJsonPath('message', ReverseTeachingUnits::ALREADY_REVERSED);

    expect(reverseScreenReversals($this->unit))->toBe(1);
});
