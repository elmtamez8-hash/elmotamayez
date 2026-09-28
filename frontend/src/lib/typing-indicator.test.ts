import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { TYPING_EXPIRY_MS, WHISPER_INTERVAL_MS, throttleWhisper, useTypingIndicator } from "./typing-indicator";

/*
| «يكتب…» (production, 2026-09-28).
|
| ⚠️ A WHISPER HAS NO «STOPPED» EVENT, so the receiving side is a timer: somebody
| who typed one letter and closed the tab must stop «typing» on the other screen.
| And their own message is the most certain «stopped» there is.
|
| Fake timers and plain `act()` — never `userEvent`, which awaits real timers
| between its steps and hangs on a clock nothing advances
| (`docs/gotchas/frontend.md`, `ConfirmButton`).
*/

const teacher = { uuid: "t-1", name: "أ. سارة" };

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe("useTypingIndicator", () => {
  it("shows the typist and forgets them after the expiry", () => {
    const { result } = renderHook(() => useTypingIndicator());

    act(() => result.current.heard(teacher));
    expect(result.current.typing).toEqual(teacher);

    act(() => vi.advanceTimersByTime(TYPING_EXPIRY_MS - 1));
    expect(result.current.typing).toEqual(teacher);

    act(() => vi.advanceTimersByTime(1));
    expect(result.current.typing).toBeNull();
  });

  it("holds while the whispers keep coming", () => {
    const { result } = renderHook(() => useTypingIndicator());

    act(() => result.current.heard(teacher));
    act(() => vi.advanceTimersByTime(WHISPER_INTERVAL_MS));
    act(() => result.current.heard(teacher));
    act(() => vi.advanceTimersByTime(WHISPER_INTERVAL_MS));

    // Four seconds since the first whisper, two since the last: still typing.
    expect(result.current.typing).toEqual(teacher);
  });

  it("clears at once when the typist's own message arrives, and not for anybody else's", () => {
    const { result } = renderHook(() => useTypingIndicator());

    act(() => result.current.heard(teacher));
    act(() => result.current.settle("somebody-else"));
    expect(result.current.typing).toEqual(teacher);

    act(() => result.current.settle(teacher.uuid));
    expect(result.current.typing).toBeNull();
  });
});

describe("throttleWhisper", () => {
  it("whispers at most once per interval, however fast the keys", () => {
    let clock = 0;
    const send = vi.fn();
    const keystroke = throttleWhisper(send, WHISPER_INTERVAL_MS, () => clock);

    for (let i = 0; i < 10; i++) {
      keystroke();
      clock += 150;
    }

    // 10 keys over 1.5 s: one frame.
    expect(send).toHaveBeenCalledTimes(1);

    clock = WHISPER_INTERVAL_MS;
    keystroke();

    expect(send).toHaveBeenCalledTimes(2);
  });

  it("keeps the interval inside the expiry, so a steady typist never appears to stop", () => {
    expect(WHISPER_INTERVAL_MS).toBeLessThan(TYPING_EXPIRY_MS);
  });
});
