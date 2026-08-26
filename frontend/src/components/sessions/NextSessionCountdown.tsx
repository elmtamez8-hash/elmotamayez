"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import type { SessionBooking } from "@/lib/class-sessions";
import { formatSessionTime } from "@/lib/session-format";

/**
 * How early the room link is worth offering.
 *
 * A ceiling of ours, not the server's rule — `sessions.join_window_minutes` is a
 * `platform_settings` row and the door reads it. Fifteen minutes matches its
 * default; being a few minutes out here shows a link that refuses politely,
 * where being three DAYS out was the defect.
 */
const OPENS_WITHIN_SECONDS = 15 * 60;

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

      {/*
        ⚠️ NO `aria-live`, AND IT HAD ONE ON A PARAGRAPH THAT CHANGES EVERY
        SECOND. A screen reader re-read the whole sentence once a second, for as
        long as the page was open — a countdown is glanceable by nature and there
        is nothing here to announce. `role="timer"` is what the value IS, and the
        static `aria-label` is what a reader hears instead of the tick.
      */}
      <p
        className="mb-4 text-sm text-ink"
        role="timer"
        aria-label={`موعد الحصة ${formatSessionTime(session.starts_at, session.timezone)}`}
      >
        {remaining === 0 ? (
          // The counter floors at zero and had no branch for it, so a lesson that
          // had already begun read «يبدأ بعد ٠ ساعة و٠ دقيقة و٠ ثانية» — for ever.
          <span className="font-semibold">بدأت الآن</span>
        ) : (
          <>
            يبدأ بعد{" "}
            <bdi className="font-semibold">
              {days > 0 && `${days} يوم و`}
              {hours} ساعة و{minutes} دقيقة و{seconds} ثانية
            </bdi>
          </>
        )}
      </p>

      {/*
        ⚠️ THE BUTTON APPEARS WHEN THE DOOR CAN ACTUALLY OPEN. It used to be
        drawn for a lesson three days away, and tapping it hit the uniform
        refusal — «تأكّد من حجز مقعدك», about a seat the student holds, on a
        session they are watching a countdown for. The server owns the real
        window; any honest ceiling beats a button that always refuses.
      */}
      {remaining > OPENS_WITHIN_SECONDS ? (
        <p className="text-sm text-ink-muted">يُفتح الدخول قبل الموعد بربع ساعة.</p>
      ) : (
        <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>
      )}
    </Card>
  );
}
