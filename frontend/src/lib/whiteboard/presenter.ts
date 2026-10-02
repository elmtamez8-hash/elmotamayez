import { arabicDigits } from "@/lib/numerals";

/**
 * The presenter tools' arithmetic (spec 039 · US8) — no DOM, tested as numbers.
 * Like every overlay these never touch the page (FR-021, SC-010).
 */

/** Countdowns offered in one press, in minutes. */
export const TIMER_PRESETS = [1, 3, 5, 10];

export interface TimerState {
  /** When it runs out (ms since epoch), or null while paused. */
  endsAt: number | null;
  /** Milliseconds left at the moment it was paused. */
  pausedLeft: number;
}

export function startTimer(minutes: number, now: number): TimerState {
  return { endsAt: now + minutes * 60_000, pausedLeft: 0 };
}

export function remainingMs(timer: TimerState, now: number): number {
  return timer.endsAt === null ? timer.pausedLeft : Math.max(0, timer.endsAt - now);
}

export function pauseTimer(timer: TimerState, now: number): TimerState {
  return timer.endsAt === null ? timer : { endsAt: null, pausedLeft: remainingMs(timer, now) };
}

export function resumeTimer(timer: TimerState, now: number): TimerState {
  return timer.endsAt !== null ? timer : { endsAt: now + timer.pausedLeft, pausedLeft: 0 };
}

/** «٤:٠٥» — whole seconds, rounded UP so «٠:٠٠» shows only once time is truly up. */
export function formatClock(ms: number): string {
  const total = Math.ceil(ms / 1000);
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  return arabicDigits(`${minutes}:${String(seconds).padStart(2, "0")}`);
}

/** The spotlight's radius limits, in screen pixels. */
export const SPOTLIGHT = { min: 80, max: 420, step: 40, initial: 180 };

export function resizeSpotlight(radius: number, grow: boolean): number {
  const next = radius + (grow ? SPOTLIGHT.step : -SPOTLIGHT.step);
  return Math.min(SPOTLIGHT.max, Math.max(SPOTLIGHT.min, next));
}
