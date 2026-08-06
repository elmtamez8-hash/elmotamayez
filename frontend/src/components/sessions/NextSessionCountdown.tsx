"use client";

import { useEffect, useState } from "react";
import Link from "next/link";

import { Card } from "@/components/ui/Card";
import type { SessionBooking } from "@/lib/class-sessions";
import { formatSessionTime } from "@/lib/session-format";

/**
 * The next session and how long is left (FR-053).
 *
 * The starting number of seconds comes from the server. The browser only ticks
 * it down — it never computes it from `starts_at` minus `Date.now()`, because a
 * machine whose clock is an hour out would then count down to a moment that does
 * not exist, and the student would arrive an hour late believing they were early
 * (SC-016).
 */
export function NextSessionCountdown({
  booking,
  secondsUntilStart,
}: {
  booking: SessionBooking | null;
  secondsUntilStart: number;
}) {
  const [remaining, setRemaining] = useState(secondsUntilStart);

  useEffect(() => {
    setRemaining(secondsUntilStart);
  }, [secondsUntilStart]);

  useEffect(() => {
    if (booking === null) return;

    const timer = setInterval(() => {
      setRemaining((value) => Math.max(0, value - 1));
    }, 1000);

    return () => clearInterval(timer);
  }, [booking]);

  if (booking === null || booking.session === undefined) {
    // An answer, not an error (FR-055).
    return (
      <Card>
        <p className="text-sm text-ink-muted">لا حصص قادمة في جدولك.</p>
      </Card>
    );
  }

  const session = booking.session;
  const days = Math.floor(remaining / 86400);
  const hours = Math.floor((remaining % 86400) / 3600);
  const minutes = Math.floor((remaining % 3600) / 60);
  const seconds = remaining % 60;

  return (
    <Card>
      <p className="mb-1 text-sm text-ink-muted">حصتك القادمة</p>
      <h3 className="mb-2 text-lg font-bold text-ink">{session.title}</h3>

      <p className="mb-3 text-sm text-ink-muted">
        {formatSessionTime(session.starts_at, session.timezone)}
      </p>

      <p className="mb-4 text-sm text-ink" aria-live="polite">
        يبدأ بعد{" "}
        <bdi className="font-semibold">
          {days > 0 && `${days} يوم و`}
          {hours} ساعة و{minutes} دقيقة و{seconds} ثانية
        </bdi>
      </p>

      <Link
        href={`/sessions/${session.uuid}/room`}
        className="inline-flex rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        دخول الغرفة
      </Link>
    </Card>
  );
}
