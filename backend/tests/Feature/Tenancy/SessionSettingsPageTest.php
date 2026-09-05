<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Tenancy\Filament\Pages\ManageSessionSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/*
| ⛔ SIXTEEN NUMBERS RUN EVERY LIVE SESSION ON THE PLATFORM AND NOT ONE HAD A
| SCREEN.
|
| `config/sessions.php` opens by saying so — «a limit that can only change by
| shipping code is a limit nobody ever tunes» — and FR-021أ forbids hard-coding
| the attendance ladder outright. The only way to move any of them was editing a
| production row by hand: no record of who, no allowed range, no cache flush.
|
| ⚠️ AND FOUR OF THEM WERE NOT IN `PlatformSettings::KEYS`. They resolved
| correctly, because `SessionSettings` passes each fallback explicitly — which is
| exactly what makes the defect invisible. It is the fourth instance in that map
| after the store pair and the billing pair, so the guard here is generic over the
| page's own declaration rather than a list of four names that ages the day a
| fifth field is added.
*/

/** @return array<int, string> */
function sessionFormFieldNames(): array
{
    $names = [];

    $walk = function (array $components) use (&$walk, &$names): void {
        foreach ($components as $component) {
            /*
            | ⚠️ IMPORTED, NOT WRITTEN INLINE. `use Filament\Facades\Filament`
            | above aliases the first segment, so a qualified
            | `Filament\Forms\Components\Field` here resolves to
            | `Filament\Facades\Filament\Forms\Components\Field` — a class that
            | does not exist, so `instanceof` is quietly FALSE for every component
            | and the walker returns an empty list. Which reads as «the page
            | declares no fields» rather than as a broken test.
            */
            if ($component instanceof Field) {
                $names[] = $component->getName();
            }

            // `getChildComponents()`, not `getDefaultChildComponents()`: a
            // `Section::make()->schema([...])` reports nothing from the latter, so
            // the walk stopped at the first section and «no fields at all» read as
            // «the map is empty» rather than as a broken walker.
            if (method_exists($component, 'getChildComponents')) {
                $walk($component->getChildComponents());
            }
        }
    };

    // `form()` is an instance method on a Page (a Resource's is static), and the
    // schema is given the page as its Livewire owner so the components resolve.
    $page = new ManageSessionSettings;

    $walk($page->form(Schema::make($page))->getComponents());

    return $names;
}

function actAsPlatformAdmin(): User
{
    $admin = User::factory()->create(['is_super_admin' => true]);

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('writes only keys the settings map knows', function (): void {
    /*
    | The guard for the defect this page was built alongside: a key the panel
    | WRITES and `KEYS` does not know is worse than one merely missing — the
    | operator changes it and the flush beside the write does not invalidate it.
    | Generic over `fields()`, so a seventeenth field is covered the day it lands.
    */
    // Collected rather than asserted one by one: the failure then NAMES every
    // orphan key at once instead of stopping at the first.
    $orphans = [];

    foreach (ManageSessionSettings::fields() as $field => $spec) {
        if (! array_key_exists($spec['key'], PlatformSettings::KEYS)) {
            $orphans[$field] = $spec['key'];
        }
    }

    expect($orphans)->toBe([]);
});

it('declares exactly the fields the form draws', function (): void {
    // Two opposite mistakes, one assertion each: a field on the screen with no
    // key writes nothing and reverts on the next visit; a key in the map with no
    // field throws on `save()` reading a missing index.
    $declared = array_keys(ManageSessionSettings::fields());
    $drawn = sessionFormFieldNames();

    sort($declared);
    sort($drawn);

    expect($drawn)->toBe($declared);
});

it('saves every field and reads it back through the real reader', function (): void {
    /*
    | ⚠️ THE ASSERTIONS GO THROUGH `SessionSettings`, NEVER BACK THROUGH
    | `PlatformSettings::get()` WITH THE SAME KEY THE PAGE JUST WROTE. Reading the
    | key the writer used proves the string equals itself; the product reads these
    | rows through that class, so a key spelled one way in the page and another in
    | the reader passes a self-comparison and shows the operator a number nothing
    | uses.
    */
    actAsPlatformAdmin();

    Livewire::test(ManageSessionSettings::class)
        ->assertOk()
        ->set('data.timezone', 'Asia/Riyadh')
        ->set('data.grace_minutes', 7)
        ->set('data.absence_threshold_ratio', 0.25)
        ->set('data.required_stay_ratio', 0.6)
        ->set('data.teacher_required_stay_ratio', 0.9)
        ->set('data.attendance_edit_window_hours', 72)
        ->set('data.join_window_minutes', 22)
        ->set('data.ticket_ttl_minutes', 8)
        ->set('data.presence_interval_seconds', 45)
        ->set('data.max_participants', 80)
        ->set('data.cancellation_window_minutes', 720)
        ->set('data.private_request_ttl_hours', 24)
        ->set('data.private_request_max_pending', 5)
        ->set('data.report_delay_minutes', 30)
        ->set('data.recording_failure_alert_threshold', 9)
        ->set('data.recording_failure_alert_window_hours', 12)
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SessionSettings::class);

    // Unsaved on purpose: `ratioOfDuration()` reads `duration_minutes` and
    // nothing else, and a persisted session would need a workspace, a course and
    // a teacher to prove one multiplication.
    $session = new ClassSession(['duration_minutes' => 100]);

    expect($settings->timezone())->toBe('Asia/Riyadh')
        ->and($settings->graceMinutes())->toBe(7)
        ->and($settings->absenceThresholdSeconds($session))->toBe(1500)
        ->and($settings->requiredStaySeconds($session))->toBe(3600)
        ->and($settings->teacherRequiredStaySeconds($session))->toBe(5400)
        ->and($settings->attendanceEditWindowHours())->toBe(72)
        ->and($settings->joinWindowMinutes())->toBe(22)
        ->and($settings->ticketTtlMinutes())->toBe(8)
        ->and($settings->presenceIntervalSeconds())->toBe(45)
        // Derived, not stored — one missed beat tolerated, an hour of silence not.
        ->and($settings->maxPingCreditSeconds())->toBe(90)
        ->and($settings->maxParticipants())->toBe(80)
        ->and($settings->cancellationWindowMinutes())->toBe(720)
        ->and($settings->privateRequestTtlHours())->toBe(24)
        ->and($settings->privateRequestMaxPending())->toBe(5)
        ->and($settings->reportDelayMinutes())->toBe(30)
        ->and($settings->recordingFailureAlertThreshold())->toBe(9)
        ->and($settings->recordingFailureAlertWindowHours())->toBe(12);
});

it('opens on the values in force rather than on empty boxes', function (): void {
    /*
    | The failure this prevents is not a blank screen: `mount()` reads through
    | `SessionSettings`, which carries each fallback, and FOUR of these keys have
    | no row on production at all. A raw read would draw an empty box over a
    | number that is working, and the operator saves it as zero believing they
    | changed nothing — a zero required-stay ratio marks every student Present the
    | instant they arrive.
    */
    PlatformSettings::set('sessions.join_window_minutes', 33, null);

    actAsPlatformAdmin();

    Livewire::test(ManageSessionSettings::class)
        ->assertFormSet([
            'join_window_minutes' => 33,
            // Never written by anybody: the config fallback must reach the box.
            'ticket_ttl_minutes' => 10,
            'max_participants' => 50,
        ]);
});

it('refuses a value that would empty the attendance ladder', function (): void {
    // Zero in either ratio is the shape a raw production edit could store today
    // and the form cannot: «absent» would fire at the start of every session, and
    // «present» the moment anybody arrived.
    actAsPlatformAdmin();

    Livewire::test(ManageSessionSettings::class)
        ->set('data.required_stay_ratio', 0)
        ->call('save')
        ->assertHasFormErrors(['required_stay_ratio']);

    expect((float) PlatformSettings::get('sessions.required_stay_ratio', 0.5))->toBe(0.5);
});

it('is registered on the panel and not only written to a file', function (): void {
    /*
    | ⚠️ A PAGE IN A DIRECTORY NOBODY DISCOVERS IS A SCREEN THAT DOES NOT EXIST —
    | no route, no navigation entry, and no error anywhere. That is why this class
    | lives under `Modules/Tenancy/Filament/Pages` beside `ManagePlatformSettings`,
    | which `AdminPanelProvider` already discovers, rather than under LiveSessions,
    | which it does not. This asserts the consequence rather than the line.
    */
    actAsPlatformAdmin();

    $this->get('/admin/session-settings')->assertOk();
});

it('is a platform screen and not a teacher one', function (): void {
    expect(ManageSessionSettings::canAccess())->toBeFalse();

    $teacher = User::factory()->create();
    $this->actingAs($teacher);

    expect(ManageSessionSettings::canAccess())->toBeFalse();

    actAsPlatformAdmin();

    expect(ManageSessionSettings::canAccess())->toBeTrue();
});
