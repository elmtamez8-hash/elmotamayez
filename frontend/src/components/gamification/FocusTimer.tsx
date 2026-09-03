"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { gamification, type FocusSession } from "@/lib/gamification";

/**
 * A study session, with the optional notifications muted for its duration.
 *
 * ⚠️ THE COUNTDOWN IS A DISPLAY, NOT THE VERDICT. Whether a session counts as
 * complete is decided by the SERVER from `now() − started_at`; if this component
 * decided it, a "120 minute" session would finish the moment somebody edited the
 * clock, and the daily cap would be all that stood between a student and an
 * afternoon of free experience.
 *
 * The elapsed time is likewise derived from `started_at` rather than counted by
 * an interval — a backgrounded tab throttles timers, so a counted-down number
 * drifts and the button then says "finished" minutes after it was.
 */
const CHOICES = [15, 25, 45];

function remainingSeconds(session: FocusSession): number {
  const started = new Date(session.started_at).getTime();
  const total = session.planned_minutes * 60;
  const elapsed = Math.floor((Date.now() - started) / 1000);

  return Math.max(0, total - elapsed);
}

function clock(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;

  return `${String(minutes).padStart(2, "0")}:${String(rest).padStart(2, "0")}`;
}

export function FocusTimer({ onFinished }: { onFinished?: () => void }) {
  const [session, setSession] = useState<FocusSession | null>(null);
  const [minutes, setMinutes] = useState(25);
  const [left, setLeft] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const tick = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (session === null) {
      if (tick.current) clearInterval(tick.current);
      return;
    }

    setLeft(remainingSeconds(session));
    tick.current = setInterval(() => setLeft(remainingSeconds(session)), 1000);

    return () => {
      if (tick.current) clearInterval(tick.current);
    };
  }, [session]);

  const start = () => {
    setBusy(true);
    setError(null);

    gamification.focus
      .start(minutes)
      .then(setSession)
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setBusy(false));
  };

  const stop = () => {
    if (session === null) return;

    setBusy(true);
    setError(null);

    gamification.focus
      .end(session.uuid)
      .then(() => {
        setSession(null);
        onFinished?.();
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setBusy(false));
  };

  return (
    <Card>
      <div className="space-y-4">
        <div>
          <h2 className="text-base font-semibold text-ink">مؤقّت التركيز</h2>
          <p className="text-sm text-ink-muted">
            تُكتم الإشعارات غير الضرورية طوال الجلسة. التنبيهات الأمنية والمالية تصلك كما هي.
          </p>
        </div>

        {error !== null && <p className="text-sm text-danger-ink">{error}</p>}

        {session === null ? (
          <div className="flex flex-wrap items-center gap-2">
            {CHOICES.map((choice) => (
              <Button
                key={choice}
                variant={minutes === choice ? "primary" : "ghost"}
                onClick={() => setMinutes(choice)}
              >
                <bdi>{choice}</bdi> دقيقة
              </Button>
            ))}
            <Button onClick={start} disabled={busy}>
              ابدأ
            </Button>
          </div>
        ) : (
          <div className="flex flex-wrap items-center gap-4">
            <p aria-live="polite" className="text-3xl font-bold text-ink tabular-nums">
              {/* Latin digits inside an Arabic run: <bdi> keeps the clock from
                  being reordered around it. */}
              <bdi>{clock(left)}</bdi>
            </p>
            <Button variant="ghost" onClick={stop} disabled={busy}>
              أنهِ الجلسة
            </Button>
            {left === 0 && (
              <p className="text-sm text-ink-muted">انتهى الوقت — أنهِ الجلسة لتُحتسب.</p>
            )}
          </div>
        )}
      </div>
    </Card>
  );
}
