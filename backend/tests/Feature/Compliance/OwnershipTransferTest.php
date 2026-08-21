<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Jobs\TransferDataOwnershipJob;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Notifications\Models\Notification;

/**
 * FR-009 — a student who turns eighteen owns their own data, and is told once.
 *
 * ⚠️ THE INTERESTING FAILURE IS NOT THE TRANSFER, IT IS THE REPETITION.
 * `date_of_birth <= today − 18y` is true again tomorrow and every day after, so a
 * sweep with no mark notifies every adult on the platform every night for the rest
 * of their life — the `notified_dormant_at` defect, reached from a new door. A
 * test that runs the job ONCE cannot see it; both cases here run it twice.
 */
function retentionStudentBorn(string $date, bool $estimated = false): User
{
    $user = User::factory()->create(['platform_role' => 'student']);

    StudentProfile::query()->create([
        'user_id' => $user->getKey(),
        'registered_by_parent' => false,
        'date_of_birth' => $date,
        'dob_is_estimated' => $estimated,
    ]);

    return $user;
}

/*
 * ⚠️ BORN EXACTLY EIGHTEEN YEARS AGO TODAY, NOT A DAY EARLIER — and that one word
 * is what found the defect. The column is declared `date` and the model writes
 * `2008-08-21 00:00:00` into it, so `<= '2008-08-21'` compares as a STRING and is
 * false for the very person the job exists for: they would be told a day late, on
 * a date the law attaches meaning to. A fixture born "yesterday-ish" is green
 * against both the broken predicate and the correct one.
 */
it('transfers ownership once and notifies once, however many nights pass', function (): void {
    $student = retentionStudentBorn(now()->subYears(18)->toDateString());

    TransferDataOwnershipJob::dispatchSync();
    TransferDataOwnershipJob::dispatchSync();

    $profile = StudentProfile::query()->where('user_id', $student->getKey())->sole();

    expect($profile->ownership_transferred_at)->not->toBeNull()
        ->and(Notification::query()
            ->where('recipient_user_id', $student->getKey())
            ->where('type', 'data_ownership_transferred')
            ->count())->toBe(1);
});

it('leaves a minor alone', function (): void {
    $student = retentionStudentBorn(now()->subYears(15)->toDateString());

    TransferDataOwnershipJob::dispatchSync();

    expect(StudentProfile::query()->where('user_id', $student->getKey())->sole()->ownership_transferred_at)
        ->toBeNull()
        ->and(Notification::query()->where('recipient_user_id', $student->getKey())->count())->toBe(0);
});

/*
 * ⚠️ T136 — AN ESTIMATED BIRTH DATE IS SPREAD ACROSS THE YEAR, AND WITHOUT THAT
 * EVERY ONE OF THEM COMES OF AGE ON THE SAME NIGHT.
 *
 * The backfill derived a date from a guardian's stated AGE — a whole number of
 * years anchored on the day it ran — so every student who was "17" that day shares
 * one date to the second, and eighteen years later they all cross together on a
 * queue that runs one process, each one a notification dispatch.
 *
 * The offset only ever DELAYS, which is this repository's standing tie-break at
 * the eighteen boundary: an estimate that is wrong should leave somebody a minor,
 * never make them an adult early.
 */
it('spreads estimated birthdays instead of releasing the whole cohort in one night', function (): void {
    $sameDate = now()->subYears(18)->toDateString();

    $students = collect(range(1, 12))->map(fn (): User => retentionStudentBorn($sameDate, estimated: true));

    TransferDataOwnershipJob::dispatchSync();

    $transferred = StudentProfile::query()
        ->whereIn('user_id', $students->map->getKey())
        ->whereNotNull('ownership_transferred_at')
        ->count();

    /*
     * Not "zero" and not "twelve": with the offset derived from the user id, only
     * whoever happens to land on day 0 goes tonight, and the rest follow over the
     * year. Asserting `< 12` is the honest form — the exact number depends on
     * which ids the fixture drew, and pinning it would make this test a hostage to
     * autoincrement.
     */
    expect($transferred)->toBeLessThan(12);

    // And an exact-date student with no estimate is unaffected by any of it.
    $known = retentionStudentBorn($sameDate);

    TransferDataOwnershipJob::dispatchSync();

    expect(StudentProfile::query()->where('user_id', $known->getKey())->sole()->ownership_transferred_at)
        ->not->toBeNull();
});
