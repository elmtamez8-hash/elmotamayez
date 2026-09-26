<?php

declare(strict_types=1);

use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Filament\Resources\BreachReportResource\Pages\ListBreachReports;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| The breach queue's buttons in `/admin` — the only place a report can be moved.
|
| `PATCH /manage/compliance/breach-reports/{uuid}` existed with its Action, its
| guards and its tests, and nothing called it: the panel listed reports read-only,
| so every report stayed «بلاغ جديد» for ever while its deadline counted down.
|
| ⚠️ TWO WORKSPACES, AND THE OFFICER'S FALLBACK IS NEITHER. A breach belongs to no
| workspace, but the officer's permission is a platform one resolved through
| `Gate::before` in whatever workspace their context lands on — a fixture with one
| workspace cannot tell that apart from a permission that only works at home.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$home] = $this->createWorkspaceWithOwner(['name' => 'Home Academy']);
    [, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'Away Academy']);

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
    $this->officer->forceFill(['last_workspace_id' => $home->getKey()])->save();

    $this->report = BreachReport::query()->create(['description' => 'روابطُ تسجيلاتٍ مكشوفةٌ في محرّك بحث.'])->refresh();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('walks a report from new to closed through the Action', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(ListBreachReports::class)
        ->assertCanSeeTableRecords([$this->report])
        ->callTableAction('triage', $this->report, data: ['affected_subject_count' => 40])
        ->assertHasNoTableActionErrors();

    expect($this->report->refresh()->status)->toBe(BreachStatus::Triaged)
        ->and($this->report->affected_subject_count)->toBe(40);

    Livewire::test(ListBreachReports::class)
        ->callTableAction('contain', $this->report)
        ->callTableAction('record_authority_notice', $this->report)
        ->callTableAction('record_subjects_notice', $this->report);

    $report = $this->report->refresh();

    expect($report->status)->toBe(BreachStatus::Contained)
        ->and($report->authority_notified_at)->not->toBeNull()
        ->and($report->subjects_notified_at)->not->toBeNull();

    Livewire::test(ListBreachReports::class)
        ->callTableAction('mark_notified', $report)
        ->assertNotified('اعتُمد اكتمال الإخطار');

    expect($report->refresh()->status)->toBe(BreachStatus::Notified);

    Livewire::test(ListBreachReports::class)
        ->callTableAction('close', $report);

    expect($report->refresh()->status)->toBe(BreachStatus::Closed)
        ->and($report->closed_at)->not->toBeNull();
});

it('refuses notified before both notices exist and says why, leaving the row as it was', function (): void {
    $this->actingAs($this->officer);

    // Only the authority side ticked: the Action stamps it in memory, then refuses.
    Livewire::test(ListBreachReports::class)
        ->callTableAction('mark_notified', $this->report, data: ['authority_notified' => true])
        ->assertNotified('لا يمكن اعتماد الإبلاغ قبل تسجيل إخطارِ الجهة المختصّة وإخطارِ المعنيّين.');

    $report = $this->report->refresh();

    expect($report->status)->toBe(BreachStatus::Reported)
        ->and($report->authority_notified_at)->toBeNull()
        ->and($report->subjects_notified_at)->toBeNull();
});

it('never re-stamps a notice and offers no button to try', function (): void {
    $this->actingAs($this->officer);

    $stamped = CarbonImmutable::parse('2026-09-01 10:00:00');
    $this->report->forceFill(['authority_notified_at' => $stamped])->save();

    Livewire::test(ListBreachReports::class)
        ->assertTableActionHidden('record_authority_notice', $this->report)
        ->assertTableActionVisible('record_subjects_notice', $this->report)
        // The equal-status scope edit goes through the same Action and must not move it.
        ->callTableAction('scope', $this->report, data: ['affected_subject_count' => 3]);

    expect($this->report->refresh()->authority_notified_at?->equalTo($stamped))->toBeTrue()
        ->and($this->report->status)->toBe(BreachStatus::Reported)
        ->and($this->report->affected_subject_count)->toBe(3);
});

it('offers no backward step on a report that has moved on', function (): void {
    $this->actingAs($this->officer);

    $this->report->forceFill(['status' => BreachStatus::Contained])->save();

    Livewire::test(ListBreachReports::class)
        ->assertTableActionHidden('triage', $this->report)
        ->assertTableActionHidden('contain', $this->report)
        ->assertTableActionVisible('mark_notified', $this->report)
        ->assertTableActionVisible('close', $this->report);

    $this->report->forceFill(['status' => BreachStatus::Closed])->save();

    Livewire::test(ListBreachReports::class)
        ->assertTableActionHidden('scope', $this->report)
        ->assertTableActionHidden('mark_notified', $this->report)
        ->assertTableActionHidden('close', $this->report);
});

it('refuses the whole screen, and so every button, to a teacher', function (): void {
    $this->actingAs($this->teacher);

    $this->get(ListBreachReports::getUrl())->assertForbidden();

    expect($this->report->refresh()->status)->toBe(BreachStatus::Reported);
});

it('refuses an officer whose two-factor grace has run out, in the mandate\'s own words', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    $this->actingAs($this->officer->refresh());

    Livewire::test(ListBreachReports::class)
        ->callTableAction('contain', $this->report)
        ->assertNotified('التحقّق بخطوتين مطلوب');

    expect($this->report->refresh()->status)->toBe(BreachStatus::Reported);
});

it('asks the same second factor at the API door the panel asks', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    Sanctum::actingAs($this->officer->refresh());

    $this->patchJson("/api/v1/manage/compliance/breach-reports/{$this->report->uuid}", [
        'status' => BreachStatus::Contained->value,
    ])->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    expect($this->report->refresh()->status)->toBe(BreachStatus::Reported);
});
