<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Models\User;
use App\Modules\Community\Events\PeriodicReviewPublished;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use IntlDateFormatter;

/**
 * The student is told, and their authorised guardians with them (FR-029).
 *
 * ⚠️ IT NAMES A RECIPIENT AND A TYPE, NEVER A CHANNEL. The guardian fan-out is
 * `NotificationType::targetsGuardians()` plus `requiredGuardianPermission()`,
 * resolved inside `DispatchNotification` — a guardian list built here would be a
 * second answer to a question `RecipientResolver` already owns, and it would skip
 * the re-check that a queued delivery makes at send time.
 *
 * ⚠️ AND THE VARIABLES ARE AN ORDERED LIST AT THE PROVIDER. WhatsApp carries the
 * template NAME and positional parameters, so a payload built by walking whatever
 * a caller happened to write puts a date where a name belongs in a message to a
 * parent, with no error anywhere. The keys here match the seeder's `variables`
 * array, which is the order that travels.
 */
class NotifyPeriodicReviewPublished implements ShouldQueue
{
    public function __construct(private readonly DispatchNotification $notifications) {}

    public function handle(PeriodicReviewPublished $event): void
    {
        $review = $event->review;

        $student = User::query()->find($review->student_user_id);
        $teacher = User::query()->find($review->teacher_user_id);

        if (! $student instanceof User || ! $teacher instanceof User) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::PeriodicReviewPublished,
            variables: [
                'student_name' => $student->name,
                'teacher_name' => $teacher->name,
                /*
                | ⚠️ ARABIC, NOT `toDateString()`. This sentence is read on a
                | parent's phone, and `2026-08-01` is a machine format in Latin
                | digits inside an approved Arabic template — the same mixed-numeral
                | defect `lib/numerals.ts` exists to close on screen, reached from
                | the one surface that has no stylesheet to fix it later.
                */
                'period_start' => $this->arabicDate($review->period_start),
                'period_end' => $this->arabicDate($review->period_end),
            ],
            actionUrl: '/reviews',
            workspaceId: (int) $review->workspace_id,
        ));
    }

    /**
     * «١ أغسطس ٢٠٢٦» — the same `ar-QA` locale every number on the platform is
     * pinned to.
     *
     * ⚠️ `IntlDateFormatter` AND NOT `Carbon::isoFormat()`. Carbon translates the
     * MONTH NAME from its own tables and leaves the digits alone, so `ar_QA` there
     * returns «1 أغسطس 2026» — an Arabic month between two Latin numbers, which is
     * the mixed-numeral tell this product has already had to fix on three screens.
     * ICU is what knows that `ar-QA` numbers in Arabic-Indic. Measured, not assumed.
     */
    private function arabicDate(CarbonInterface $date): string
    {
        $formatter = new IntlDateFormatter(
            'ar-QA',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
        );

        return (string) $formatter->format($date);
    }
}
