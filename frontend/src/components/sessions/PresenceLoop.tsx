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

    const beat = async () => {
      try {
        const state = await classSessions.presence(sessionUuid);
        if (cancelled) return;

        setMissedBeats(0);
        onUpdate?.(state);
      } catch {
        if (!cancelled) setMissedBeats((count) => count + 1);
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
      انقطع اتصالك بالغرفة — لن يُحتسب وقتك حتى يعود.
    </p>
  );
}
