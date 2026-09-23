import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

import { CancelBookingButton } from "./CancelBookingButton";
import { ApiError } from "@/lib/api";

/*
| «إلغاء الحجز» — `DELETE /bookings/{uuid}` had no caller at all.
|
| ⚠️ THE COST IS THE POINT, SO BOTH SIDES OF THE DEADLINE ARE ASSERTED. A late
| cancellation is accepted AND STILL CHARGED (FR-010); a confirm that said the
| same thing on either side would let somebody give up a paid seat believing it
| was free. One case alone passes against a build that prints one sentence for
| everybody.
|
| `fireEvent`, never `userEvent` — see `Modal.test.tsx`.
*/

const del = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { delete: (path: string) => del(path) },
}));

const DEADLINE = "2026-10-01T08:00:00Z";
const BEFORE = () => Date.parse("2026-09-30T08:00:00Z");
const AFTER = () => Date.parse("2026-10-01T09:00:00Z");

function renderButton(now: () => number, onCancelled = vi.fn()) {
  render(
    <CancelBookingButton
      bookingUuid="b-1"
      mayCancelUntil={DEADLINE}
      timezone="Asia/Qatar"
      onCancelled={onCancelled}
      now={now}
    />,
  );

  fireEvent.click(screen.getByRole("button", { name: "إلغاء الحجز" }));

  return onCancelled;
}

/*
 | ⚠️ NO `mockReset()`/`mockClear()` BETWEEN CASES. Measured under vitest 4.1: a
 | rejection returned by a mock that was reset beforehand surfaces as a failure of
 | the test even though the component caught it — so each case sets its own
 | implementation and counts calls relative to where it started.
 */
describe("CancelBookingButton", () => {

  it("says the cancellation is free inside the window, then cancels", async () => {
    del.mockResolvedValue({ uuid: "b-1", status: "cancelled_in_window", status_label: "أُلغي في المهلة" });

    const onCancelled = renderButton(BEFORE);

    expect(screen.getByText(/الإلغاء مجاني حتى/)).toBeTruthy();
    expect(screen.queryByText(/محتسبة عليك/)).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "ألغِ الحجز" }));

    await waitFor(() =>
      expect(onCancelled).toHaveBeenCalledWith({ status: "cancelled_in_window", status_label: "أُلغي في المهلة" }),
    );
    expect(del).toHaveBeenCalledWith("/bookings/b-1");
  });

  it("warns that a late cancellation is still charged, BEFORE the press", () => {
    const sent = del.mock.calls.length;
    renderButton(AFTER);

    expect(screen.getByText(/تبقى الحصة محتسبة عليك/)).toBeTruthy();
    expect(screen.queryByText(/الإلغاء مجاني حتى/)).toBeNull();
    expect(screen.getByRole("button", { name: "ألغِ مع احتسابها" })).toBeTruthy();
    // Nothing is sent until the student confirms.
    expect(del.mock.calls.length).toBe(sent);
  });

  it("shows the server's refusal as a sentence and reports nothing cancelled", async () => {
    const refused = new ApiError("هذا الحجز ملغى بالفعل.", 409, { message: "هذا الحجز ملغى بالفعل." });
    del.mockRejectedValue(refused);

    const onCancelled = renderButton(BEFORE);
    fireEvent.click(screen.getByRole("button", { name: "ألغِ الحجز" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(onCancelled).not.toHaveBeenCalled();
  });

  it("sends nothing when the student backs out", () => {
    const sent = del.mock.calls.length;
    renderButton(BEFORE);

    fireEvent.click(screen.getByRole("button", { name: "إلغاء" }));

    expect(del.mock.calls.length).toBe(sent);
  });
});
