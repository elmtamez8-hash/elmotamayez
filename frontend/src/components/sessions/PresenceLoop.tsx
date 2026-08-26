"use client";

import { useEffect, useState } from "react";

import { classSessions, type PresenceState } from "@/lib/class-sessions";

/**
 * The heartbeat. Attendance is nothing but this loop and the server's
 * arithmetic.
 *
 * Same shape as the watermark's renewal loop in spec 004, and for the same
 * reason: the client keeps saying "still here", the server decides what that is
 * worth. Nothing is computed in the browser — `stay_seconds` and the status
 * come back from the server on every beat, so a page with a tampered clock or a
 * patched script still cannot award itself attendance.
 *
 * A single failed beat is not an error worth showing. The server's crediting cap
 * is two intervals, so one dropped request costs nothing; only a sustained
 * silence stops the clock, and that is the truth about where the student was.
 */
/** Consecutive failures before the loop gives up rather than posting for ever. */
const GIVE_UP_AFTER_BEATS = 6;

export function PresenceLoop({
  sessionUuid,
  intervalSeconds,
  onUpdate,
}: {
  sessionUuid: string;
  intervalSeconds: number;
  onUpdate?: (state: PresenceState) => void;
}) {
  const [missedBeats, setMissedBeats] = useState(0);

  useEffect(() => {
    let cancelled = false;
    let missed = 0;

    const beat = async () => {
      try {
        const state = await classSessions.presence(sessionUuid);
        if (cancelled) return;

        missed = 0;
        setMissedBeats(0);
        onUpdate?.(state);
      } catch {
        if (cancelled) return;

        missed += 1;
        setMissedBeats(missed);

        /*
          ⚠️ IT STOPS, AND IT NEVER USED TO.

          The loop only ever counted. A student's tab left open after the lesson —
          or one refused for good — kept posting every thirty seconds for as long
          as the page lived, and each of those posts re-runs the whole join
          eligibility chain on the server. Thirty abandoned tabs is a room's worth
          of heartbeat traffic for a lesson that ended.

          The threshold is generous on purpose. The server credits up to two
          intervals, so giving up at two would stop a loop that could still have
          been recovering; by six the student has been silent for three minutes
          and nothing is being credited anyway. Reloading resumes it, which is the
          honest way back — the register is the server's, not this component's.
        */
        if (missed >= GIVE_UP_AFTER_BEATS) clearInterval(timer);
      }
    };

    void beat();
    const timer = setInterval(() => void beat(), intervalSeconds * 1000);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [sessionUuid, intervalSeconds, onUpdate]);

  // Two missed beats is exactly the point where the server stops crediting, so
  // it is the first moment the student is losing something by not knowing.
  if (missedBeats < 2) return null;

  return (
    <p role="status" className="text-sm text-danger-ink">
      {/* Once the loop has stopped, «حتى يعود» is a promise it can no longer
          keep — nothing will retry on its own, so the sentence has to name the
          one thing that does. */}
      {missedBeats >= GIVE_UP_AFTER_BEATS
        ? "توقّف تسجيل حضورك. أعِد تحميل الصفحة إن كنت ما زلت في الحصة."
        : "انقطع اتصالك بالغرفة — لن يُحتسب وقتك حتى يعود."}
    </p>
  );
}
