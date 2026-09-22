<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Identity\Jobs\EnforceAuthSessionCapJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\IdentityPersonalData;
use App\Shared\Data\DataSubject;
use App\Shared\Support\GuardianPermission;
use Illuminate\Console\Scheduling\Schedule;

/*
| ⛔ THE OWNER'S EXPORT DECISION (2026-09-22), AND IT HAD NO TEST AT ALL.
|
| `ip_hash` is an unsalted SHA-256 over a space of 2^32 addresses — a readable
| address, not a hash — so it goes to the ACCOUNT HOLDER alone and is withheld
| from an archive a guardian or a delegate opened. `IdentityPersonalData`'s
| `$ownRequest` ternary is the ENTIRE control: `ExportFieldAllowlist` cannot
| catch a regression here, because `ip_hash` is deliberately absent from
| `forbiddenKeys()` and that file says why.
|
| Measured: deleting the ternary left the whole suite green. A custody dispute
| is exactly the case the decision was made for, and nothing measured it.
|
| ⚠️ AND BOTH HALVES ARE REQUIRED. «the guardian's row has no `ip_hash`» is
| vacuously true of an arm that yields the guardian nothing at all — which is a
| different bug with the same green tick. So each case asserts the ROW IS THERE
| first, and the control proves the field is reachable when it should be.
*/

/** @return array<string, list<array<string, mixed>>> */
function exportedByCategory(DataSubject $subject): array
{
    $yielded = [];

    foreach (app(IdentityPersonalData::class)->export($subject) as $category => $rows) {
        $yielded[$category] = array_merge($yielded[$category] ?? [], $rows);
    }

    return $yielded;
}

beforeEach(function (): void {
    $this->student = User::factory()->create();

    $device = Device::factory()->create(['user_id' => $this->student->getKey()]);

    // The factory writes a real 64-character `ip_hash`, so the row carries an
    // address to withhold. A fixture without one cannot fail either case.
    $this->session = AuthSession::factory()->ended()->create([
        'user_id' => $this->student->getKey(),
        'device_id' => $device->getKey(),
    ]);
});

it('gives the account holder their own addresses', function (): void {
    // `grantedScope` null IS «the subject asked for their own record».
    $rows = exportedByCategory(new DataSubject($this->student))['auth_session'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKey('ip_hash')
        ->and($rows[0]['ip_hash'])->toBe($this->session->ip_hash);
});

it('withholds the address from an archive a guardian opened', function (): void {
    $rows = exportedByCategory(new DataSubject(
        $this->student,
        grantedScope: [GuardianPermission::DataRights],
    ))['auth_session'];

    // ⚠️ THE ROW IS STILL THERE. The guardian is entitled to the sign-in
    // history — when and from which door — and only to the address inside it.
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKey('surface')
        ->and($rows[0])->toHaveKey('started_at')
        ->and($rows[0])->not->toHaveKey('ip_hash');
});

it('never exports a fingerprint to anyone', function (): void {
    // The device arm sends `label`, never `fingerprint_hash` — and unlike
    // `ip_hash` that one is banned outright, for the owner too.
    foreach ([new DataSubject($this->student), new DataSubject($this->student, grantedScope: [GuardianPermission::DataRights])] as $subject) {
        $rows = exportedByCategory($subject)['device'];

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->not->toHaveKey('fingerprint_hash');
    }
});

it('has the cap job actually on the schedule, after the sweep', function (): void {
    /*
    | ⛔ A JOB NOBODY DISPATCHES IS A FEATURE NOBODY HAS. Delete the
    | `Schedule::job(new EnforceAuthSessionCapJob, …)` line and every other case
    | in this feature stays green — they all dispatch it by hand. The cap would
    | simply never run in production, and the row count would grow for ever with
    | nothing on any screen saying so.
    |
    | ⚠️ AND THE ORDER IS PART OF THE REQUIREMENT, NOT A PREFERENCE: anonymisation
    | is what PRODUCES this job's candidates (`ip_hash IS NULL`), so a cap that
    | ran before the sweep would work on yesterday's set for ever.
    */
    $due = [];

    foreach (app(Schedule::class)->events() as $event) {
        foreach ([RunRetentionSweepJob::class, EnforceAuthSessionCapJob::class] as $job) {
            if (str_contains($event->description ?? '', $job)) {
                $due[$job] = $event->expression;
            }
        }
    }

    expect($due)->toHaveKey(RunRetentionSweepJob::class)
        ->and($due)->toHaveKey(EnforceAuthSessionCapJob::class);

    // Both are `m H * * *`; the sweep must come first on the same day.
    $minutes = static function (string $cron): int {
        [$m, $h] = explode(' ', $cron);

        return ((int) $h * 60) + (int) $m;
    };

    expect($minutes($due[EnforceAuthSessionCapJob::class]))
        ->toBeGreaterThan($minutes($due[RunRetentionSweepJob::class]));
});
