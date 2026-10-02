import { describe, expect, it } from "vitest";

import { formatClock, pauseTimer, remainingMs, resizeSpotlight, resumeTimer, SPOTLIGHT, startTimer } from "@/lib/whiteboard/presenter";

describe("the countdown", () => {
  it("counts down, pauses without losing time, and resumes from where it stopped", () => {
    const started = startTimer(5, 0);
    expect(remainingMs(started, 60_000)).toBe(240_000);

    const paused = pauseTimer(started, 60_000);
    expect(remainingMs(paused, 999_999)).toBe(240_000); // time passes, the clock does not

    const resumed = resumeTimer(paused, 1_000_000);
    expect(remainingMs(resumed, 1_030_000)).toBe(210_000);
    expect(remainingMs(resumed, 9_999_999)).toBe(0); // never below zero
  });

  it("reads in Arabic digits, rounding up so zero shows only at the end", () => {
    expect(formatClock(245_000)).toBe("٤:٠٥");
    expect(formatClock(500)).toBe("٠:٠١");
    expect(formatClock(0)).toBe("٠:٠٠");
  });
});

describe("the spotlight", () => {
  it("grows and shrinks within its limits", () => {
    expect(resizeSpotlight(SPOTLIGHT.initial, true)).toBe(SPOTLIGHT.initial + SPOTLIGHT.step);
    expect(resizeSpotlight(SPOTLIGHT.max, true)).toBe(SPOTLIGHT.max);
    expect(resizeSpotlight(SPOTLIGHT.min, false)).toBe(SPOTLIGHT.min);
  });
});
