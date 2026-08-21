<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Compliance\Events\TeacherOffboardingRequested;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;

/**
 * Everyone studying with a departing teacher is told, with the date (FR-033).
 *
 * ⚠️ AT REQUEST, NOT AT COMPLETION, WHICH IS THE ENTIRE REQUIREMENT. FR-033 asks
 * for an ANNOUNCED period BEFORE service stops; a message sent when the exit
 * completes is not a notice, it is an obituary. The date it carries is
 * `notice_ends_at`, computed once at request for exactly this reason — recomputed
 * later it would move a deadline people had already been given.
 *
 * ⚠️ AND THE GUARDIANS COME FOR FREE, WHICH IS WHY NOTHING HERE MENTIONS THEM.
 * `TeacherOffboardingNotice` declares `targetsGuardians()` and a
 * `requiredGuardianPermission()` of `Schedule`, so `RecipientResolver` fans each
 * message out to whoever is authorised. A listener that resolved guardians itself
 * would be a second answer to a question `DispatchNotification` already owns —
 * and the one that gets it wrong the day a permission changes.
 */
class NotifyOffboardingStudents
{
    private const BATCH = 200;

    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(TeacherOffboardingRequested $event): void
    {
        $offboarding = $event->offboarding;

        $teacher = $offboarding->teacher;

        if ($teacher === null || $offboarding->notice_ends_at === null) {
            return;
        }

        // `Y-m-d`, the same shape `NotifySeatHolders` sends: the template is Arabic
        // text with a date in it, and a localised month name is a second thing to
        // keep in step with the provider-side template that actually renders it.
        $noticeEnds = $offboarding->notice_ends_at->format('Y-m-d');

        /*
        | ⚠️ DISTINCT STUDENTS, NOT ENROLMENTS. A student taking three of this
        | teacher's courses is one person with one phone; three identical messages
        | in a minute is how a family mutes the number, taking the attendance alert
        | with it. `chunkById` rather than `chunk` for the usual reason — but the
        | ids are collected first and deduplicated, because the predicate here does
        | not shrink and the duplication is what has to go.
        */
        $seen = [];

        Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $offboarding->workspace_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(self::BATCH, function ($enrollments) use (&$seen, $teacher, $noticeEnds): void {
                $ids = [];

                foreach ($enrollments as $enrollment) {
                    $id = (int) $enrollment->student_user_id;

                    if (isset($seen[$id])) {
                        continue;
                    }

                    $seen[$id] = true;
                    $ids[] = $id;
                }

                if ($ids === []) {
                    return;
                }

                foreach (User::query()->whereIn('id', $ids)->get() as $student) {
                    $this->dispatch->handle(new NotificationRequest(
                        recipient: $student,
                        type: NotificationType::TeacherOffboardingNotice,
                        // ⚠️ THE TEMPLATE'S OWN VARIABLE NAMES, read from the
                        // seeder rather than guessed: a payload key a template does
                        // not declare renders nothing and the message is dropped in
                        // silence (FR-037 of spec 003).
                        variables: [
                            'teacher_name' => $teacher->first_name,
                            'notice_end_date' => $noticeEnds,
                        ],
                        actionUrl: '/dashboard',
                        subject: $student,
                    ));
                }
            });

        TeacherOffboarding::query()
            ->whereKey($offboarding->getKey())
            ->whereNull('students_notified_at')
            ->update(['students_notified_at' => now(), 'updated_at' => now()]);
    }
}
