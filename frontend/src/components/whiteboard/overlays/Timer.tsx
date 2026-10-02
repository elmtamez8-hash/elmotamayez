"use client";

import { useEffect, useState } from "react";

import { playGavel } from "@/lib/whiteboard/effect-sounds";
import { formatClock, pauseTimer, remainingMs, resumeTimer, startTimer, type TimerState } from "@/lib/whiteboard/presenter";
import { WB } from "@/lib/whiteboard/strings";

/**
 * A countdown on the board for an exercise (US8) — big Arabic digits the class
 * reads through the stream. Pause, resume, close; at zero it flashes and, if the
 * effects' sound is on, knocks. A display layer only (FR-021).
 */
export function Timer({ minutes, sound, onClose }: { minutes: number; sound: boolean; onClose: () => void }) {
  const [timer, setTimer] = useState<TimerState>(() => startTimer(minutes, Date.now()));
  const [now, setNow] = useState(() => Date.now());
  const left = remainingMs(timer, now);
  const done = left === 0;
  const paused = timer.endsAt === null;

  useEffect(() => {
    if (paused || done) return;
    const tick = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(tick);
  }, [paused, done]);

  useEffect(() => {
    if (done && sound) playGavel();
  }, [done, sound]);

  return (
    <div className="absolute inset-x-0 bottom-6 mx-auto w-fit" style={{ zIndex: 6 }} data-effect="timer">
      <style>{`@keyframes wb-flash { 50% { opacity: 0.35; } }`}</style>
      <div
        className="flex items-center gap-3 rounded-2xl px-6 py-3 shadow-xl"
        style={{ background: done ? "#d62828" : "#111827", color: "#ffffff", animation: done ? "wb-flash 0.8s 4" : undefined }}
      >
        <span role="timer" aria-live="off" className="font-extrabold tabular-nums" style={{ fontSize: 64, lineHeight: 1 }}>
          {done ? WB.presenter.timeUp : formatClock(left)}
        </span>
        {!done && (
          <button
            type="button"
            onClick={() => setTimer((t) => (paused ? resumeTimer(t, Date.now()) : pauseTimer(t, Date.now())))}
            className="rounded-lg bg-white/15 px-3 py-1 text-sm font-semibold hover:bg-white/25"
          >
            {paused ? WB.presenter.resume : WB.presenter.pause}
          </button>
        )}
        <button type="button" onClick={onClose} className="rounded-lg bg-white/15 px-3 py-1 text-sm font-semibold hover:bg-white/25">
          {WB.presenter.closeTimer}
        </button>
      </div>
    </div>
  );
}
