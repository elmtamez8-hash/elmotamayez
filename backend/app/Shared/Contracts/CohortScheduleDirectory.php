<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * "When does this group actually meet?"
 *
 * Owned by `LiveSessions`, which owns the session; asked by the group picker,
 * which is Learning's screen. ⚠️ WITHOUT IT THE PICKER IS A LIST OF NAMES, and
 * FR-028أ says in as many words that choosing between bare names is not
 * choosing: a student picking «السبت ٤م» over «الأحد ٦م» is picking a time, and
 * the time is the one fact the group's own row does not hold.
 *
 * ⚠️ BULK BY SIGNATURE. It is read once per group on a screen listing every
 * group of a course — asked per row it is the `ClassSessionResource` N+1 arriving
 * through a new door.
 */
interface CohortScheduleDirectory
{
    /**
     * The distinct weekday-and-time slots each of these groups meets on, as
     * short Arabic labels, most frequent first.
     *
     * ⚠️ A SUMMARY, NOT A TIMETABLE, which is why it is text rather than
     * timestamps: the question the picker asks is "which slot suits me", and a
     * list of thirty upcoming instants answers it by making the reader do the
     * grouping. The full schedule is the sessions tab, and it sends raw
     * timestamps like everything else.
     *
     * @param  list<int>  $cohortIds
     * @return array<int, list<string>> keyed by cohort id; a group with no
     *                                  sessions yet is an EMPTY list, never a
     *                                  missing key — the caller renders "no
     *                                  sessions scheduled" rather than nothing
     */
    public function schedulePreviewFor(array $cohortIds): array;

    /**
     * The next session this group will hold, or null when none is scheduled.
     *
     * ⚠️ SINGLE-ROW, AND THAT IS NOT A BREACH OF THE BULK RULE ABOVE. This is
     * asked ONCE per approved subscription, from a queued listener building one
     * notification — not once per row of a list. The bulk rule exists because a
     * Resource runs per row; nothing here does.
     *
     * ⚠️ AND THE CALLER MUST RENDER ITS ABSENCE (FR-029أ). A group whose next
     * session is not scheduled yet answers null, and the notification says so in
     * words — dropping the line instead makes its absence read as a fault.
     *
     * @return array{uuid: string, starts_at: string}|null
     */
    public function nextSessionFor(int $cohortId): ?array;
}
