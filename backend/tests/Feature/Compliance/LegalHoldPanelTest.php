<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Filament\Resources\LegalHoldResource\Pages\ListLegalHolds;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| The hold screen in `/admin` — the only place a legal hold can be placed or lifted.
|
| `POST` and `DELETE /manage/compliance/holds` existed with their Actions, their
| policy and their tests, and nothing in `frontend/src` or the panel called either:
| the panel listed holds read-only, so a court order could be recorded by nobody.
|
| ⚠️ TWO WORKSPACES, AND THE OFFICER'S FALLBACK IS NEITHER THE SUBJECT'S. Any test
| of a platform-permission write needs that (docs/gotchas/compliance.md): a context
| resolved to the officer's own workspace is what stranded `ExecuteTeacherOffboarding`.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$home] = $this->createWorkspaceWithOwner(['name' => 'Home Academy']);
    [$away, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'Away Academy']);

    $this->subject = $this->addWorkspaceMember($away, Roles::STUDENT);

    $this->request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
    $this->officer->forceFill(['last_workspace_id' => $home->getKey()])->save();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('places a hold from the panel and suspends the open erasure', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(ListLegalHolds::class)
        ->assertActionVisible('place')
        ->callAction('place', data: [
            'subject' => (string) $this->subject->uuid,
            'reason' => 'أمر قضائي رقم ١٢',
        ])
        ->assertHasNoActionErrors();

    $hold = LegalHold::query()->sole();

    expect((int) $hold->subject_user_id)->toBe((int) $this->subject->getKey())
        ->and((int) $hold->placed_by_user_id)->toBe((int) $this->officer->getKey())
        ->and($hold->reason)->toBe('أمر قضائي رقم ١٢')
        // Through `PlaceLegalHold`, not a bare insert: the open request is suspended.
        ->and(DataRequest::query()->whereKey($this->request->getKey())->value('status'))
        ->toBe(DataRequestStatus::OnHold);
});

it('refuses a hold with no reason', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(ListLegalHolds::class)
        ->callAction('place', data: ['subject' => (string) $this->subject->uuid, 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect(LegalHold::query()->count())->toBe(0);
});

it('releases a hold from its row and returns the request to the queue', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(ListLegalHolds::class)
        ->callAction('place', data: ['subject' => (string) $this->subject->uuid, 'reason' => 'أمر قضائي']);

    $hold = LegalHold::query()->sole();

    Livewire::test(ListLegalHolds::class)
        ->assertCanSeeTableRecords([$hold])
        ->callTableAction('release', $hold)
        ->assertHasNoTableActionErrors();

    expect($hold->refresh()->released_at)->not->toBeNull()
        ->and((int) $hold->released_by_user_id)->toBe((int) $this->officer->getKey())
        // Released, never destroyed: the row is the record.
        ->and(LegalHold::query()->count())->toBe(1)
        ->and(DataRequest::query()->whereKey($this->request->getKey())->value('status'))
        ->toBe(DataRequestStatus::Pending);

    // A released hold offers no second release.
    Livewire::test(ListLegalHolds::class)
        ->assertTableActionHidden('release', $hold);
});

it('shows neither button to a teacher', function (): void {
    // A teacher holds no platform permission, so the page itself refuses them.
    $this->actingAs($this->teacher);

    $this->get(ListLegalHolds::getUrl())->assertForbidden();

    expect(LegalHold::query()->count())->toBe(0);
});
