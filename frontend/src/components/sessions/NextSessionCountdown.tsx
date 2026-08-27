"use client";

import { useEffect, useState } from "react";

import { ClockIcon } from "@/components/icons";
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
  const open = remaining <= OPENS_WITHIN_SECONDS;

  return (
    <Card>
      <p className="mb-1 flex items-center gap-2 text-sm text-ink-muted">
        {/*
          ⚠️ THE DOT PULSES ONLY WHILE THE DOOR IS ACTUALLY OPEN, and it is a
          decoration on a sentence that already says so — never the only carrier
          of the fact. A live indicator on a lesson three days away is the same
          lie the button below was telling before it grew a window. `bg-secondary`
          because there is no `success` COLOUR token in `@theme`: the tone name
          exists in `TONE_CLASSES` alone, and `bg-success` paints nothing at all.
        */}
        {open && (
          <span aria-hidden className="relative flex h-2 w-2 shrink-0">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-secondary opacity-60" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-secondary" />
          </span>
        )}
        حصتك القادمة
      </p>
      <h3 className="mb-2 text-lg font-bold text-ink">{session.title}</h3>

      <p className="mb-3 flex items-center gap-1.5 text-sm text-ink-muted">
        {/* `className` REPLACES the icon's default size, so h-4 w-4 is repeated. */}
        <ClockIcon className="h-4 w-4 shrink-0" />
        {formatSessionTime(session.starts_at, session.timezone)}
      </p>

      {/*
        ⚠️ NO `aria-live`, AND IT HAD ONE ON A PARAGRAPH THAT CHANGES EVERY
        SECOND. A screen reader re-read the whole sentence once a second, for as
        long as the page was open — a countdown is glanceable by nature and there
        is nothing here to announce. `role="timer"` is what the value IS, and the
        static `aria-label` is what a reader hears instead of the tick.
      */}
      <div
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
            <p className="mb-2">يبدأ بعد</p>
            {/*
              ⚠️ SEGMENTED, AND THE UNITS ARE WRITTEN OUT UNDER EACH NUMBER. Four
              bare numerals separated by colons is a convention nobody agreed to
              in Arabic, and it reverses badly: `dir="ltr"` on the row is what
              keeps «days hours minutes seconds» in that order, while each cell
              stays a plain number that needs no bidi handling of its own.
            */}
            <div dir="ltr" className="flex gap-2">
              {[
                ...(days > 0 ? [[days, "يوم"] as const] : []),
                [hours, "ساعة"] as const,
                [minutes, "دقيقة"] as const,
                [seconds, "ثانية"] as const,
              ].map(([value, unit]) => (
                <span
                  key={unit}
                  className="flex min-w-14 flex-col items-center rounded-xl border border-line bg-surface px-2 py-1.5"
                >
                  {/* `tabular-nums` and NOT `font-mono`: the digits must stop
                      the row twitching once a second, and a second typeface on
                      one card is a second design system. */}
                  <span className="text-xl font-bold leading-none tabular-nums text-ink">
                    {String(value).padStart(2, "0")}
                  </span>
                  <span className="mt-1 text-xs text-ink-muted">{unit}</span>
                </span>
              ))}
            </div>
          </>
        )}
      </div>

      {/*
        ⚠️ THE BUTTON APPEARS WHEN THE DOOR CAN ACTUALLY OPEN. It used to be
        drawn for a lesson three days away, and tapping it hit the uniform
        refusal — «تأكّد من حجز مقعدك», about a seat the student holds, on a
        session they are watching a countdown for. The server owns the real
        window; any honest ceiling beats a button that always refuses.
      */}
      {open ? (
        <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>
      ) : (
        <p className="text-sm text-ink-muted">يُفتح الدخول قبل الموعد بربع ساعة.</p>
      )}
    </Card>
  );
}
