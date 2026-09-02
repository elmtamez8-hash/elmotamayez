import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  PrivateSessionRequestForm,
  startsWithin,
} from "./PrivateSessionRequestForm";
import { ApiError } from "@/lib/api";
import type { AvailabilityItem } from "@/lib/public-api";

/*
| Spec 023 · T050 — the form, and the two things only a component test can see.
|
| ⚠️ THE PAYLOAD CARRIES ONE FIELD. FR-016أ says the teacher declares the length
| and the student READS it; a `duration_minutes` in the body would let the browser
| decide what a credit buys, what the teacher is paid for and how much of their
| calendar goes. The backend does not know this form exists and would happily
| ignore an extra key — so nothing on that side can fail when one appears.
|
| ⚠️ AND THE UNENROLLED READER IS NOT SHOWN A BUTTON (FR-015أ). A control that is
| pressed and then refused teaches the reader the product is broken; the sentence
| they need is the same either way and is worth more before the press.
*/

const request = vi.fn();
const nextForCourse = vi.fn();

vi.mock("@/lib/private-sessions", () => ({
  privateSessions: {
    request: (...args: unknown[]) => request(...args),
  },
}));

vi.mock("@/lib/class-sessions", () => ({
  classSessions: {
    nextForCourse: (...args: unknown[]) => nextForCourse(...args),
  },
}));

const WINDOWS: AvailabilityItem[] = [
  { day_of_week: 2, start_time: "14:00", end_time: "18:00" },
];

function enrolled() {
  localStorage.setItem("auth_token", "t");
  nextForCourse.mockResolvedValue({ data: null });
}

beforeEach(() => {
  localStorage.clear();
  request.mockReset();
  nextForCourse.mockReset();
});

describe("startsWithin", () => {
  it("builds every slot in UTC, because that is what the window is stored in", () => {
    const slots = startsWithin(WINDOWS, 45, new Date("2026-09-06T09:00:00Z"), 1);

    /*
     | ⚠️ THE DISCRIMINATING ASSERTION, AND IT IS THE WHOLE REASON THIS CASE
     | EXISTS. `availability_slots` holds UTC and `RequestPrivateSession`
     | compares `starts_at->utc()->format('H:i:s')` against it — so a form built
     | with `setHours()` renders local 14:00 and submits 11:00Z on a UTC+3
     | machine, and the server refuses four of the five hours it just offered.
     |
     | Compared on `toISOString()`, never on `getHours()`: the local pair reads
     | both sides of the comparison in the same wrong timezone, so it agrees with
     | itself on every machine — including a broken build. Measured on a UTC+3
     | machine on 2026-09-02, where the old spelling produced `T11:00`.
     */
    expect(slots[0].toISOString()).toContain("T14:00");
    expect(slots[0].toISOString()).toContain("2026-09-08");
  });

  it("offers only slots whose WHOLE duration fits the window", () => {
    const slots = startsWithin(WINDOWS, 45, new Date("2026-09-06T09:00:00Z"), 1);
    const times = slots.map((s) => s.toISOString().slice(11, 16));

    // The grid IS the duration — back-to-back hours, the way a day is taught.
    // 14:00 + 4×45 = 17:00 is the last start; 17:45 would finish at 18:30.
    expect(times).toContain("17:00");
    expect(times).not.toContain("17:45");

    // FR-016ب is about the END, not the start — every offered slot ends by 18:00.
    for (const slot of slots) {
      const end = new Date(slot.getTime() + 45 * 60_000);
      expect(end.toISOString().slice(11, 16) <= "18:00").toBe(true);
    }
  });

  it("offers nothing in the past", () => {
    const noon = new Date("2026-09-08T16:00:00Z");

    // Tuesday the 8th, mid-window. Everything before now is gone; the window's
    // own opening hour is not «available» merely because it is declared.
    for (const slot of startsWithin(WINDOWS, 45, noon, 1)) {
      expect(slot.getTime()).toBeGreaterThan(noon.getTime());
    }
  });
});

describe("PrivateSessionRequestForm", () => {
  it("invites a signed-out reader to sign in instead of offering a button", async () => {
    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={45}
      />,
    );

    expect(await screen.findByText(/سجّل الدخول/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "أرسل الطلب" })).toBeNull();
    // And it never asks the API a question about somebody who is not signed in.
    expect(nextForCourse).not.toHaveBeenCalled();
  });

  it("invites a signed-in reader who did not buy the course to enrol first", async () => {
    localStorage.setItem("auth_token", "t");
    // A REAL `ApiError`: `errorCode` reads the BODY and `userMessage` narrows on
    // the class, so a plain object here would be a test that agrees with itself
    // and proves nothing about either.
    nextForCourse.mockRejectedValue(
      new ApiError("لا تملك تسجيلاً في هذا الكورس.", 403, {
        message: "لا تملك تسجيلاً في هذا الكورس.",
        code: "not_enrolled",
      }),
    );

    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={45}
      />,
    );

    expect(await screen.findByText(/اشترك في هذا الكورس أوّلاً/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "أرسل الطلب" })).toBeNull();
  });

  it("sends the chosen instant and nothing else", async () => {
    enrolled();
    request.mockResolvedValue({});

    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={45}
      />,
    );

    // ⚠️ `fireEvent`, AND NO FAKE CLOCK. `findBy*` polls on real timers, so a
    // suite that froze the clock to pin «now» would hang on every await here and
    // time out rather than fail — the shape `ConfirmButton`'s test wrote down
    // from the other direction. `startsWithin` takes its `from` as an argument
    // precisely so the pure half can be pinned without freezing anything.
    const slot = (await screen.findAllByRole("button"))[0];
    fireEvent.click(slot);
    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));

    const [courseUuid, startsAt] = request.mock.calls[0];

    expect(courseUuid).toBe("c-1");
    expect(typeof startsAt).toBe("string");
    // TWO arguments, and the second is an instant. A third would be the duration.
    expect(request.mock.calls[0]).toHaveLength(2);
  });

  it("reads the declared duration and never asks the reader for one", async () => {
    enrolled();

    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={90}
      />,
    );

    expect(await screen.findByText(/٩٠ دقيقة|90 دقيقة/)).toBeTruthy();
    // No control offers a length: it is the teacher's, and the student reads it.
    expect(screen.queryByLabelText(/مدة/)).toBeNull();
  });

  it("falls back to the platform default rather than hiding itself", async () => {
    enrolled();

    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={null}
      />,
    );

    // NULL means «the platform default», never «no private sessions in this
    // course» — read the other way the section would be invisible on every course
    // whose teacher never opened the field.
    expect(await screen.findByText(/٦٠ دقيقة|60 دقيقة/)).toBeTruthy();
  });

  it("puts the server's refusal on the screen as a sentence", async () => {
    enrolled();
    request.mockRejectedValue(
      new ApiError("هذا الوقت خارج مواعيد المدرّس المعلَنة.", 422, {
        message: "هذا الوقت خارج مواعيد المدرّس المعلَنة.",
      }),
    );

    render(
      <PrivateSessionRequestForm
        courseUuid="c-1"
        availability={WINDOWS}
        minutes={45}
      />,
    );

    const slot = (await screen.findAllByRole("button"))[0];
    fireEvent.click(slot);
    fireEvent.click(screen.getByRole("button", { name: "أرسل الطلب" }));

    // Never a raw error and never a blank: the sentence names what to do next.
    expect(
      await screen.findByText("هذا الوقت خارج مواعيد المدرّس المعلَنة."),
    ).toBeTruthy();
  });
});
