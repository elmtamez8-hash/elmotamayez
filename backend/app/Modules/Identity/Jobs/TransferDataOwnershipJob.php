<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Models\User;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * A student who has turned eighteen owns their own data (spec 013 · FR-009).
 *
 * ⚠️ THE NAME IS THE DESIGN. An earlier draft called this `ExpireDataOwnership`,
 * which says the OPPOSITE of what happens: nothing expires — a guardian's
 * authority ends and the person's own begins, with no interruption of service and
 * nothing deleted. In a repository that renamed `Session` to `ClassSession` over
 * exactly this kind of misreading, the wrong verb in a job name is a defect.
 *
 * ⚠️ AND IT LIVES IN `Identity`, NOT IN `Compliance`. The table it walks is
 * `student_profiles`, and `Compliance` names no other module's schema anywhere —
 * that is the whole reason a requirement crossing thirteen modules is expressed as
 * a tagged contract rather than as one Action that knows everybody. A job here
 * that imported `StudentProfile` would be the first exception, and the first
 * exception is what the rule is for.
 *
 * ⚠️ AND THE STAMP IS WRITTEN BEFORE THE NOTICE IS SENT. `date_of_birth <= today
 * − 18y` is true again tomorrow, so without `ownership_transferred_at` this
 * notifies every adult on the platform every night for the rest of their life.
 * The order is the `notified_dormant_at` rule verbatim: a lost notice beats one a
 * night for ever, and the two failures are not remotely equal in cost.
 */
class TransferDataOwnershipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH = 200;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('compliance');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('compliance:ownership-transfer'))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(DispatchNotification $dispatch): void
    {
        $today = CarbonImmutable::now();

        /*
        | ⚠️ `<` THE DAY AFTER, NEVER `<=` THE DAY ITSELF — AND THE DIFFERENCE IS
        | THE EIGHTEENTH BIRTHDAY, WHICH IS THE ONLY DAY THIS JOB IS ABOUT. The
        | column is declared `date`, but the model's date format writes
        | `2008-08-21 00:00:00` into it, and a string comparison makes that GREATER
        | than the bare `2008-08-21` the bound would otherwise be. So the person
        | whose birthday is today is skipped and told tomorrow — silently, on a
        | date the law attaches meaning to. The same boundary that cost
        | `FreezePeriod::covering()` and a settlement close their own fixes; on
        | MySQL both forms work, so no local test of the `<=` shape would ever have
        | disagreed with production.
        */
        $threshold = $today->subYears(18)->addDay()->toDateString();

        StudentProfile::query()
            ->whereNull('ownership_transferred_at')
            ->whereNotNull('date_of_birth')
            /*
            | ⚠️ THE INDEX'S LEADING COLUMN IS THE NULL ONE — everyone already
            | transferred is excluded before the date is compared, so this scan
            | walks a shrinking remainder rather than the whole student body.
            | Declared in the migration that added the column.
            */
            ->where('date_of_birth', '<', $threshold)
            ->orderBy('id')
            ->chunkById(self::BATCH, function ($profiles) use ($dispatch, $today): void {
                foreach ($profiles as $profile) {
                    if (! $this->hasComeOfAge($profile, $today)) {
                        continue;
                    }

                    /*
                    | ⚠️ A CONDITIONAL UPDATE, AND IT IS BOTH THE CHECK AND THE
                    | CLAIM. Two runners — a manual dispatch beside the nightly
                    | schedule — would otherwise both read null and both notify.
                    | The seat idiom, and never `lockForUpdate()`, a no-op on
                    | SQLite.
                    */
                    $claimed = StudentProfile::query()
                        ->whereKey($profile->getKey())
                        ->whereNull('ownership_transferred_at')
                        ->update(['ownership_transferred_at' => now(), 'updated_at' => now()]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $student = User::query()->find($profile->user_id);

                    if ($student === null) {
                        continue;
                    }

                    $dispatch->handle(new NotificationRequest(
                        recipient: $student,
                        type: NotificationType::DataOwnershipTransferred,
                        // The template's own variable name — a payload key it does
                        // not declare renders nothing and the message is dropped
                        // in silence (FR-037), so this is read from the seeder
                        // rather than guessed.
                        variables: ['student_name' => $student->first_name],
                        actionUrl: '/settings/privacy',
                        subject: $student,
                    ));
                }
            });
    }

    /**
     * ⚠️ AN ESTIMATED BIRTH DATE IS SPREAD ACROSS THE YEAR, AND WITHOUT THIS EVERY
     * ONE OF THEM COMES OF AGE ON THE SAME NIGHT.
     *
     * `dob_is_estimated` rows were derived from a guardian's stated AGE — a whole
     * number of years anchored on the day the backfill ran — so every student who
     * was "17" that day shares one date to the second. Eighteen years later they
     * all cross the threshold in one pass, on a queue with `maxProcesses: 1`, each
     * one a notification dispatch.
     *
     * The offset is derived from the user id, so it is stable across runs (a
     * random one would move the birthday every night and could skip it entirely),
     * and it only ever DELAYS — the estimate is already a guess, and this
     * repository breaks that tie toward the minor every time it appears.
     */
    private function hasComeOfAge(StudentProfile $profile, CarbonImmutable $today): bool
    {
        if ($profile->dob_is_estimated !== true) {
            return true;
        }

        $birthday = CarbonImmutable::parse((string) $profile->date_of_birth)
            ->addYears(18)
            ->addDays((int) $profile->user_id % 365);

        return $birthday->lessThanOrEqualTo($today);
    }
}
