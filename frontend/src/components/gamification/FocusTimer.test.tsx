import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { FocusTimer } from "./FocusTimer";
import { gamification } from "@/lib/gamification";

/*
| The focus timer's browser half.
|
| ⚠️ THE STATE MACHINE HERE IS INVISIBLE FROM THE BACKEND, which is exactly what
| vitest is for in this project: "does this component do what it says" is a
| different question from "does the product work end to end". Playwright builds
| for production and needs two servers, so it never runs inside a development
| loop; this file finishes in milliseconds.
*/
vi.mock("@/lib/gamification", () => ({
  gamification: { focus: { start: vi.fn(), end: vi.fn() } },
}));

const start = vi.mocked(gamification.focus.start);
const end = vi.mocked(gamification.focus.end);

function session(minutes: number, startedAt: Date) {
  return {
    uuid: "f-1",
    planned_minutes: minutes,
    started_at: startedAt.toISOString(),
    ended_at: null,
    status: "running" as const,
  };
}

describe("FocusTimer", () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-08-20T09:00:00Z"));
    start.mockReset();
    end.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("starts a session with the chosen duration", async () => {
    start.mockResolvedValue(session(25, new Date()) as never);

    render(<FocusTimer />);

    fireEvent.click(screen.getByRole("button", { name: /15/ }));
    fireEvent.click(screen.getByRole("button", { name: "ابدأ" }));

    await waitFor(() => expect(start).toHaveBeenCalledWith(15));
  });

  /*
   * ⚠️ THE REMAINING TIME IS DERIVED FROM `started_at`, NOT COUNTED DOWN.
   *
   * A backgrounded tab throttles intervals, so a counted number drifts and the
   * screen then claims minutes that have already passed are still to come. This
   * case travels the clock forward without letting the interval fire at all,
   * which a decrementing implementation cannot survive.
   */
  it("derives the remaining time from the start, not from ticks", async () => {
    const startedAt = new Date("2026-08-20T09:00:00Z");
    start.mockResolvedValue(session(25, startedAt) as never);

    render(<FocusTimer />);
    fireEvent.click(screen.getByRole("button", { name: "ابدأ" }));

    await screen.findByText("25:00");

    // Ten minutes pass while the tab was asleep.
    vi.setSystemTime(new Date("2026-08-20T09:10:00Z"));
    await vi.advanceTimersByTimeAsync(1000);

    expect((await screen.findByText("15:00")).textContent).toBe("15:00");
  });

  it("ends the session and tells the page to reload its numbers", async () => {
    start.mockResolvedValue(session(25, new Date()) as never);
    end.mockResolvedValue({ ...session(25, new Date()), status: "completed" } as never);

    const onFinished = vi.fn();
    render(<FocusTimer onFinished={onFinished} />);

    fireEvent.click(screen.getByRole("button", { name: "ابدأ" }));
    fireEvent.click(await screen.findByRole("button", { name: "أنهِ الجلسة" }));

    await waitFor(() => {
      expect(end).toHaveBeenCalledWith("f-1");
      expect(onFinished).toHaveBeenCalledTimes(1);
    });
  });

  it("shows an Arabic sentence when starting fails, never a raw error", async () => {
    start.mockRejectedValue(new TypeError("Failed to fetch"));

    render(<FocusTimer />);
    fireEvent.click(screen.getByRole("button", { name: "ابدأ" }));

    expect(await screen.findByText(/تعذّر الاتصال/)).toBeTruthy();
  });
});
