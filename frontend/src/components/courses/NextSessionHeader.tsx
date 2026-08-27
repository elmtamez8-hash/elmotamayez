"use client";

import { useEffect, useState } from "react";

import { ClockIcon } from "@/components/icons";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import type { ClassSession } from "@/lib/class-sessions";
import { formatSessionTime } from "@/lib/session-format";

/**
 * The next lesson of THIS course, at the top of its page (US2 · FR-015).
 *
 * ⚠️ THE DOOR'S ANSWER COMES FROM THE SERVER (`join_open`), NEVER FROM THIS
 * COUNTDOWN. The join window is a `platform_settings` row an operator tunes and
 * the room's own closure sits inside it, so a button drawn from «seconds left is
 * under fifteen minutes» is a button the server answers «تعذّر الدخول» — and it
 * goes on being drawn on a machine whose clock is wrong. `room_closed` outranks
 * both the countdown and the status: `CloseClassSessionJob` runs at `ends_at`
 * plus the window, so a teacher who ends the broadcast early leaves a session
 * `live` with the room deleted, and the page used to badge it «جارية» and keep
 * offering the button.
 *
 * ⚠️ AND THE SECONDS ARE SEEDED BY THE SERVER TOO. The browser only ticks them
 * down; computing them from `starts_at` minus `Date.now()` counts down to a
 * moment that does not exist on a machine an hour out, and the student arrives
 * late believing they were early (SC-016).
 */
export function NextSessionHeader({
  session,
  secondsUntilStart,
  secondsUntilJoinOpen = null,
}: {
  session: ClassSession | null;
  secondsUntilStart: number;
  /** `0` open now · `null` never again (closed room, or window already past). */
  secondsUntilJoinOpen?: number | null;
}) {
  const [remaining, setRemaining] = useState(secondsUntilStart);
  const [untilOpen, setUntilOpen] = useState(secondsUntilJoinOpen);

  useEffect(() => {
    setRemaining(secondsUntilStart);
    setUntilOpen(secondsUntilJoinOpen);
  }, [secondsUntilStart, secondsUntilJoinOpen]);

  /*
    ⚠️ ONE INTERVAL FOR BOTH COUNTDOWNS, AND IT RUNS WHILE EITHER IS ABOVE ZERO.
    Keyed on the start alone, a page opened inside the join window would not tick
    the door open — and a page opened before it would stop ticking the moment the
    lesson began, freezing the footer on «يُفتح الدخول» for the whole hour.
  */
  useEffect(() => {
    if (session === null) return;
    if (secondsUntilStart <= 0 && (secondsUntilJoinOpen ?? 0) <= 0) return;

    const timer = setInterval(() => {
      setRemaining((value) => Math.max(0, value - 1));
      setUntilOpen((value) => (value === null ? null : Math.max(0, value - 1)));
    }, 1000);

    return () => clearInterval(timer);
  }, [session, secondsUntilStart, secondsUntilJoinOpen]);

  if (session === null) {
    // ⚠️ A SENTENCE, NOT AN EMPTY COUNTDOWN. «لا حصّةَ قادمة» is an answer
    // (FR-015); four zeroes under «يبدأ بعد» is a page that looks broken.
    return (
      <Card>
        <p className="text-sm text-ink-muted">لا حصّة قادمة في هذه المادّة حالياً.</p>
      </Card>
    );
  }

  /*
    ⚠️ THE BOOLEAN OR THE COUNTDOWN REACHING ZERO — NEVER THE BROWSER'S CLOCK.
    `join_open` is the server's answer at fetch, and `untilOpen` is the server's
    own «how long until then», ticked down here. A page left open crosses into
    the window without a reload, and neither number was derived from `starts_at`
    minus `Date.now()` (SC-016).
  */
  const open = (session.join_open || untilOpen === 0) && !session.room_closed;
  const hours = Math.floor(remaining / 3600);
  const minutes = Math.floor((remaining % 3600) / 60);
  const seconds = remaining % 60;

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="mb-1 flex items-center gap-2 text-sm text-ink-muted">
            {/*
              The pulse marks a door that is ACTUALLY open, and it is decoration
              on a sentence that already says so. `bg-secondary` because there is
              no `success` colour token in `@theme` — the tone name exists only
              in `TONE_CLASSES`, and `bg-success` paints nothing at all, silently.
            */}
            {open && (
              <span aria-hidden className="relative flex h-2 w-2 shrink-0">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-secondary opacity-60" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-secondary" />
              </span>
            )}
            الحصّة القادمة
          </p>

          <h3 className="mb-2 text-lg font-bold text-ink">{session.title}</h3>

          <p className="flex items-center gap-1.5 text-sm text-ink-muted">
            {/* `className` REPLACES the icon's default size, so h-4 w-4 is repeated. */}
            <ClockIcon className="h-4 w-4 shrink-0" />
            {formatSessionTime(session.starts_at, session.timezone)}
          </p>
        </div>

        {/* The room being shut is a fact the badge carries whatever the status
            says — the two disagree for up to a join window on every lesson a
            teacher ends early. */}
        {session.room_closed ? (
          <Badge tone="neutral">انتهت</Badge>
        ) : (
          <Badge tone={session.status === "cancelled" ? "danger" : "info"}>
            {session.status_label}
          </Badge>
        )}
      </div>

      {/*
        ⚠️ NO `aria-live` ON A VALUE THAT CHANGES EVERY SECOND. A screen reader
        would re-read the sentence once a second for as long as the page is
        open. `role="timer"` is what it IS, and the static label is what a reader
        hears instead of the tick.
      */}
      <div
        className="mt-4 text-sm text-ink"
        role="timer"
        aria-label={`موعد الحصة ${formatSessionTime(session.starts_at, session.timezone)}`}
      >
        {remaining === 0 ? (
          <span className="font-semibold">بدأت الآن</span>
        ) : (
          <>
            <p className="mb-2">يبدأ بعد</p>
            {/* `dir="ltr"` keeps «hours minutes seconds» in that order while each
                cell stays a plain number needing no bidi handling of its own. */}
            <div dir="ltr" className="flex gap-2">
              {[
                [hours, "ساعة"] as const,
                [minutes, "دقيقة"] as const,
                [seconds, "ثانية"] as const,
              ].map(([value, unit]) => (
                <span
                  key={unit}
                  className="flex min-w-14 flex-col items-center rounded-xl border border-line bg-surface px-2 py-1.5"
                >
                  {/* `tabular-nums`, not `font-mono`: stop the row twitching once
                      a second without adding a second typeface to the page. */}
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

      <div className="mt-4">
        {open ? (
          <Button href={`/sessions/${session.uuid}/room`}>دخول الغرفة</Button>
        ) : (
          <p className="text-sm text-ink-muted">
            {session.room_closed
              ? "أُغلقت الغرفة. سيظهر التسجيل هنا حين يُنشر."
              : "يُفتح الدخول قبل الموعد بقليل."}
          </p>
        )}
      </div>
    </Card>
  );
}
