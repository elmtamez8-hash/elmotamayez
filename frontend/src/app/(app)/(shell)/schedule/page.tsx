"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { ScheduleIcon } from "@/components/icons";
import { NextSessionCountdown } from "@/components/sessions/NextSessionCountdown";
import { SessionCard } from "@/components/sessions/SessionCard";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { classSessions, type ClassSession, type SessionBooking } from "@/lib/class-sessions";
import { formatSessionDay, sessionDayKey } from "@/lib/session-format";

/**
 * The student's timetable, across every teacher they study with.
 *
 * One list, not one per teacher: the person is a platform-level entity and the
 * server reads their bookings by their own id, deliberately across workspaces.
 * The mirror of that rule is enforced on the server too — no teacher can reach
 * this list.
 *
 * ⚠️ GROUPED BY DAY, BECAUSE A TIMETABLE IS READ BY DAY. It was a flat
 * two-column grid, so «هل عندي حاجة النهاردة؟» — the only question anybody opens
 * this page with — meant reading the date on every card and comparing it against
 * today. The grouping is the answer to that question, and «اليوم» / «غداً» in
 * the heading is why the cards below it can drop the date entirely.
 */
export default function SchedulePage() {
  const [bookings, setBookings] = useState<SessionBooking[]>([]);
  const [next, setNext] = useState<SessionBooking | null>(null);
  const [secondsUntilStart, setSecondsUntilStart] = useState(0);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([classSessions.schedule(), classSessions.next()])
      .then(([list, upcoming]) => {
        setBookings(list.data ?? []);
        setNext(upcoming.data);
        setSecondsUntilStart(upcoming.seconds_until_start ?? 0);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  /*
   | The server already sorts by start time, so one pass in order is enough and
   | the days come out in order too — no second sort to disagree with the first.
   | Keyed on the day in the SESSION's zone, never the browser's: a lesson at
   | 01:00 Doha would otherwise file under yesterday for a student whose laptop
   | is on another timezone, under a heading that says «أمس» about tonight.
   */
  const days = useMemo(() => {
    // The session is unpacked here rather than at render: a booking whose
    // session the payload omitted is dropped once, and everything below it is
    // typed without a non-null assertion standing in for that check.
    const groups: { key: string; label: string; rows: { uuid: string; session: ClassSession }[] }[] = [];

    for (const booking of bookings) {
      const session = booking.session;

      if (session === undefined) continue;

      const key = sessionDayKey(session.starts_at, session.timezone);
      const last = groups.at(-1);
      const row = { uuid: booking.uuid, session };

      if (last?.key === key) {
        last.rows.push(row);
        continue;
      }

      groups.push({
        key,
        label: formatSessionDay(session.starts_at, session.timezone),
        rows: [row],
      });
    }

    return groups;
  }, [bookings]);

  return (
    <div className="space-y-6">
      <h2 className="flex items-center gap-2 text-2xl font-bold text-ink">
        <ScheduleIcon className="h-6 w-6 text-primary-ink" />
        جدولي
      </h2>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : (
        <>
          <NextSessionCountdown booking={next} secondsUntilStart={secondsUntilStart} />

          {days.length === 0 ? (
            <EmptyState
              title="لا حصص محجوزة"
              description="احجز مقعدك من صفحة مدرّسك لتظهر حصصك هنا."
            />
          ) : (
            <div className="space-y-6">
              {days.map((day, dayIndex) => (
                <section key={day.key} className="space-y-3">
                  <h3 className="flex items-center gap-3 text-sm font-semibold text-ink-muted">
                    <span>{day.label}</span>
                    {/* A rule to the end of the row, so the eye can find where
                        one day stops without another border competing with the
                        cards' own. */}
                    <span aria-hidden className="h-px flex-1 bg-line" />
                    <span className="text-xs font-normal">
                      <bdi>{day.rows.length}</bdi> حصة
                    </span>
                  </h3>

                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {day.rows.map((row, index) => (
                      /*
                        ⚠️ THE STAGGER IS CAPPED AND THE FILL MODE IS `both`.
                        `banner-rise` covers the delay as well as the animation,
                        so a card is not painted, hidden when its turn comes and
                        repainted — the flicker that only shows on staggered
                        elements. The cap keeps a full week from making the last
                        card arrive a second and a half after the first; the
                        reduced-motion block in globals.css zeroes all of it.
                      */
                      <div
                        key={row.uuid}
                        className="banner-rise"
                        style={{ animationDelay: `${Math.min(dayIndex * 2 + index, 6) * 45}ms` }}
                      >
                        <SessionCard
                          session={row.session}
                          href={`/sessions/${row.session.uuid}/room`}
                          time="clock"
                        />
                      </div>
                    ))}
                  </div>
                </section>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
