import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { SilenceControl } from "./SilenceControl";

/*
  ⚠️ `fireEvent`, NEVER `userEvent`, AND THE REASON IS THE FAKE CLOCK. The
  disarm timer inside `ConfirmButton` is the load-bearing half of the two-press
  arm, so it has to be tested — and `userEvent` awaits real timers between its
  own simulated steps. Under `useFakeTimers` it hangs on a clock nothing is
  advancing and the test TIMES OUT rather than failing, which reads as a broken
  runner instead of a broken control.
*/

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("SilenceControl", () => {
  it("will not arm until a reason is written", () => {
    const onSilence = vi.fn();

    render(
      <SilenceControl name="سامي" onSilence={onSilence} onCancel={() => undefined} />,
    );

    /*
      ⚠️ THE REASON IS THE REQUIREMENT, NOT A COURTESY. The student reads it
      inside the refusal; without one they see a send that fails and retry it
      until the ban lapses — which is the noise the whole instrument exists to
      stop. Disabled here rather than refused by the server, because a control
      that arms and then fails teaches the teacher that the button is unreliable.
    */
    fireEvent.click(screen.getByRole("button", { name: "أوقف الكتابة" }));

    expect(onSilence).not.toHaveBeenCalled();
  });

  it("takes two presses, and sends the reason with the chosen duration", () => {
    const onSilence = vi.fn();

    render(
      <SilenceControl name="سامي" onSilence={onSilence} onCancel={() => undefined} />,
    );

    fireEvent.change(screen.getByLabelText("السبب (يقرؤه الطالب)"), {
      target: { value: "مقاطعة متكرّرة" },
    });

    // First press ARMS. A control that fired here would silence a paying student
    // from a button eight pixels from «أبلِغ» at a 40px target on a phone.
    fireEvent.click(screen.getByRole("button", { name: "أوقف الكتابة" }));
    expect(onSilence).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "تأكيد الإيقاف" }));

    expect(onSilence).toHaveBeenCalledWith("مقاطعة متكرّرة", 10);
  });

  /*
    ⚠️ THE DISARM TIMER IS WHY THIS IS A TWO-PRESS ARM AND NOT A TOGGLE. Without
    it a half-pressed control lies in wait and the NEXT stray tap — minutes later,
    on a different message — fires it.
  */
  it("disarms itself when the second press does not come", () => {
    vi.useFakeTimers();

    const onSilence = vi.fn();

    render(
      <SilenceControl name="سامي" onSilence={onSilence} onCancel={() => undefined} />,
    );

    fireEvent.change(screen.getByLabelText("السبب (يقرؤه الطالب)"), {
      target: { value: "مقاطعة" },
    });

    fireEvent.click(screen.getByRole("button", { name: "أوقف الكتابة" }));
    expect(screen.getByRole("button", { name: "تأكيد الإيقاف" })).toBeTruthy();

    // ⚠️ INSIDE `act`, or the timer fires and React never flushes the state it
    // set — the button stays armed on screen and the assertion fails about the
    // test harness rather than about the control.
    act(() => {
      vi.advanceTimersByTime(10_000);
    });

    expect(screen.queryByRole("button", { name: "تأكيد الإيقاف" })).toBeNull();
    expect(onSilence).not.toHaveBeenCalled();
  });

  /*
    ⚠️ THE OPEN-ENDED OPTION IS DELIBERATELY ABSENT. The server accepts a ban with
    no expiry, but nothing in the product lifts one — so offering it here would
    ship a control whose only exit is a request typed by hand. Asserted, because
    an absence nobody measures is an absence somebody restores next week.
  */
  it("offers no ban that cannot end on its own", () => {
    render(
      <SilenceControl name="سامي" onSilence={() => undefined} onCancel={() => undefined} />,
    );

    const options = Array.from(
      (screen.getByLabelText("المدّة") as HTMLSelectElement).options,
    ).map((option) => option.value);

    expect(options).not.toContain("");
    expect(options.every((value) => Number(value) > 0)).toBe(true);
  });

  it("names the person, so the wrong row is visible before the second press", () => {
    render(
      <SilenceControl name="سامي" onSilence={() => undefined} onCancel={() => undefined} />,
    );

    expect(screen.getByText("سامي")).toBeTruthy();
  });
});
