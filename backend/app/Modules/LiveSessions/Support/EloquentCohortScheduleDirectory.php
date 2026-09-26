<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\CohortScheduleDirectory;

/**
 * The weekday-and-time pattern of a group, read from the sessions it actually
 * has.
 *
 * ⚠️ NOT FROM AN AVAILABILITY SLOT. A teacher's declared availability is what
 * they COULD teach; the picker is answering "when will I be in class", and the
 * two diverge the first time a session is rescheduled — at which point the
 * picker would be advertising a time nobody meets at.
 */
class EloquentCohortScheduleDirectory implements CohortScheduleDirectory
{
    /** Sunday-first, matching `date('w')`. */
    private const DAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    /**
     * @param  list<int>  $cohortIds
     * @return array<int, list<string>>
     */
    public function schedulePreviewFor(array $cohortIds): array
    {
        return array_map(
            static fn (array $slots): array => array_map(static fn (array $slot): string => $slot['label'], $slots),
            $this->scheduleSlotsFor($cohortIds),
        );
    }

    /**
     * ⚠️ ONE WALK FOR BOTH SHAPES. The labels and the instants come from the same
     * sessions in the same order, so the public page's structured slots and the
     * crawled text beside them can never name different meetings.
     */
    public function scheduleSlotsFor(array $cohortIds): array
    {
        /** @var array<int, list<array{at: string, label: string}>> $out */
        $out = array_fill_keys($cohortIds, []);

        if ($cohortIds === []) {
            return [];
        }

        /*
        | ⚠️ UPCOMING AND CANCELLED-FREE, AND BOUNDED. A group that has run for a
        | term has hundreds of past sessions and they all describe the same two
        | slots; reading them all to derive two strings is a query whose cost
        | grows with the age of the course on a screen a student opens once.
        */
        $sessions = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('cohort_id', $cohortIds)
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Live->value])
            ->where('starts_at', '>=', now()->subWeek())
            ->orderBy('starts_at')
            ->limit(200)
            ->get(['cohort_id', 'starts_at']);

        /** @var array<int, array<string, int>> $counts */
        $counts = [];

        /** @var array<int, array<string, string>> $instants label => the next meeting in it, else the latest */
        $instants = [];
        $now = now();

        /*
        | ⛔ THE LABEL IS BUILT IN THE PLATFORM'S TIMEZONE, AND IT WAS BUILT IN
        | UTC — measured on production 2026-09-15.
        |
        | `config('app.timezone')` is `UTC` and `sessions.timezone` is
        | `Asia/Qatar`, so a group whose teacher named it «السبت ٥م» advertised
        | «السبت 14:00» in the assignment picker: three hours early, on the one
        | string a student and an officer read to know when the class meets.
        |
        | ⚠️ AND THE DAY IS THE SHARPER HALF. A session at 01:00 Qatar is 22:00
        | the PREVIOUS day in UTC, so the weekday itself came out wrong — a
        | Sunday group labelled «السبت». The hour is a number somebody might
        | question; the weekday reads as a fact.
        |
        | Read from `SessionSettings`, which is the platform's one declaration of
        | its timezone, exactly as `ReferenceTargetController` already reads it.
        */
        $timezone = app(SessionSettings::class)->timezone();

        foreach ($sessions as $session) {
            $cohortId = (int) $session->cohort_id;
            $localStart = $session->starts_at->copy()->setTimezone($timezone);
            $label = self::DAYS[(int) $localStart->format('w')].' '.$localStart->format('H:i');

            $counts[$cohortId][$label] = ($counts[$cohortId][$label] ?? 0) + 1;

            // Ascending walk: the first future meeting wins; until one is seen,
            // each past one replaces the last, leaving the latest.
            $known = $instants[$cohortId][$label] ?? null;

            if ($known === null || $known < $now->toIso8601String()) {
                $instants[$cohortId][$label] = $session->starts_at->copy()->utc()->toIso8601String();
            }
        }

        foreach ($counts as $cohortId => $labels) {
            arsort($labels);

            $out[$cohortId] = array_map(
                static fn (string $label): array => ['at' => $instants[$cohortId][$label], 'label' => $label],
                array_slice(array_keys($labels), 0, 3),
            );
        }

        return $out;
    }

    /** @return array{uuid: string, starts_at: string, label: string}|null */
    public function nextSessionFor(int $cohortId): ?array
    {
        /*
        | ⚠️ THE SAME THREE CONDITIONS AS THE PREVIEW ABOVE, DELIBERATELY. A
        | cancelled session is not the next meeting, and a past one is not next —
        | telling a student who has just paid to turn up to a class that was
        | called off is the worst first message this feature could send.
        |
        | Ordered ascending with one row: served end to end by the
        | `(cohort_id, starts_at)` index this spec adds.
        */
        $session = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('cohort_id', $cohortId)
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Live->value])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first(['uuid', 'starts_at']);

        if ($session === null) {
            return null;
        }

        /*
        | ⛔ THE LABEL IS BUILT HERE, NOT AT THE CALL SITE — AND `starts_at` ALONE
        | REACHED A STUDENT'S NOTIFICATION AS `2026-09-26T14:00:00+00:00`.
        | Measured on production 2026-09-20 (٠٢٧ · T075): inside an Arabic
        | paragraph the bidi algorithm reorders it to `26T14:00:00+00:00-09-2026`
        | — not merely ugly, UNREADABLE — and the line directly above it already
        | said «السبت 17:00», so one meeting was printed twice in two shapes, one
        | of them machine text.
        |
        | ⚠️ AND IT IS THE SIBLING OF THE FIX ABOVE, WHICH DID NOT REACH IT.
        | `schedulePreviewFor()` was corrected on 2026-09-15 — its own comment
        | says the weekday is the sharper half, because 01:00 Qatar is 22:00 the
        | PREVIOUS day in UTC — while this method, one method below, kept
        | returning UTC. A rule fixed in one of two adjacent methods is a rule
        | half-fixed.
        |
        | ⚠️ AND THE LABEL BELONGS TO THE DIRECTORY BECAUSE FOUR CALLERS WANT IT.
        | `OrderResource` had already written its own `setTimezone(...)->format()`
        | beside its own explanation; copying that into the three notification
        | sites would have made six spellings of one rule, which is the defect
        | this repository records more than any other. `starts_at` stays as the
        | machine value — the uuid's honest sibling — and nothing formats it again.
        */
        $local = $session->starts_at->copy()->setTimezone(app(SessionSettings::class)->timezone());

        return [
            'uuid' => (string) $session->uuid,
            'starts_at' => $session->starts_at->toIso8601String(),
            'label' => self::DAYS[(int) $local->format('w')].' '.$local->format('Y-m-d H:i'),
        ];
    }
}
