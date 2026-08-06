"use client";

import { useCallback, useEffect, useState } from "react";

import { NextSessionCountdown } from "@/components/sessions/NextSessionCountdown";
import { SessionCard } from "@/components/sessions/SessionCard";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { classSessions, type SessionBooking } from "@/lib/class-sessions";

/**
 * The student's timetable, across every teacher they study with.
 *
 * One list, not one per teacher: the person is a platform-level entity and the
 * server reads their bookings by their own id, deliberately across workspaces.
 * The mirror of that rule is enforced on the server too — no teacher can reach
 * this list.
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

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">جدولي</h2>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : (
        <>
          <NextSessionCountdown booking={next} secondsUntilStart={secondsUntilStart} />

          {bookings.length === 0 ? (
            <EmptyState
              title="لا حصص محجوزة"
              description="احجز مقعدك من صفحة مدرّسك لتظهر حصصك هنا."
            />
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {bookings.map((booking) =>
                booking.session === undefined ? null : (
                  <SessionCard
                    key={booking.uuid}
                    session={booking.session}
                    href={`/sessions/${booking.session.uuid}/room`}
                  />
                ),
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}
