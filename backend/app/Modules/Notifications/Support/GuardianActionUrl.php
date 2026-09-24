<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Models\User;

/**
 * Where a GUARDIAN's copy of a notification sends them.
 *
 * ⚠️ ONE `action_url` WAS WRITTEN FOR EVERY RECIPIENT, AND IT IS THE STUDENT'S.
 * `RecipientResolver` fans a guardian-targeting type out to the child's
 * authorised guardians, and `DispatchNotification` used to hand each of them the
 * link the listener built for the child: an exam result sent the parent to
 * `/exams/{attempt}/result`, whose `GET /attempts/{uuid}` has no guardian branch
 * in `AttemptPolicy::view()` ⇒ 403; a subscription sent them into the lesson
 * ROOM; and `/schedule`, `/billing`, `/assignments` or `/shop` opened the
 * guardian's OWN pages — empty, because they are nobody's student.
 *
 * So a guardian's copy goes to the dashboard with the child selected
 * (`/dashboard?student={uuid}`): the guardian dashboard already carries that
 * child's timetable, attendance, balance and results cards, each gated by the
 * permission the guardian was granted — the same permission
 * `requiredGuardianPermission()` used to decide they hear about it at all.
 *
 * ⚠️ EXCEPT WHERE THE ORIGINAL PAGE ALREADY SERVES A GUARDIAN. Those are named,
 * not derived: `/reviews` picks the child itself, `/store` is the buyer's own
 * purchases (and the guardian is usually the buyer), and `/family` is the
 * relationship both sides see. `/dashboard` is deliberately NOT here: a bare one
 * lands on the guardian's alphabetically-first child, so it too gains the
 * `?student=` of the child the notification is about. A page added to this list
 * has to be one a guardian can actually use — the test beside this class asserts
 * the exam-result copy lands on one.
 */
final class GuardianActionUrl
{
    /** Paths whose page already answers a guardian correctly. */
    private const GUARDIAN_SAFE = ['/reviews', '/store', '/family'];

    public static function for(?string $url, User $student): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = (string) strtok($url, '?');

        if (in_array($path, self::GUARDIAN_SAFE, true)) {
            return $url;
        }

        return '/dashboard?student='.$student->uuid;
    }
}
