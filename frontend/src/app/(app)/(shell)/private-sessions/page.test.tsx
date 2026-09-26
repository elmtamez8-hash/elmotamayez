import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import type { PrivateSessionRequest } from "@/lib/private-sessions";
import { formatSessionTime } from "@/lib/session-format";
import { setStoredViewerTimeZone } from "@/lib/viewer-time-zone";

import MyPrivateSessionsPage from "./page";

/*
| «حصصي الخاصة» — the student's half of the private-session queue.
|
| ⚠️ THE CALL LIST IS THE ASSERTION, not only the text. `mine` is the one read
| this screen makes, and `withdraw` happens only after the window is confirmed —
| a withdraw on the first press, or on cancel, would take back an ask the
| student only meant to look at.
|
| `fireEvent`, never `userEvent` (see `Modal.test.tsx`).
*/

const mine = vi.fn();
const withdraw = vi.fn();

vi.mock("@/lib/private-sessions", () => ({
  privateSessions: {
    mine: () => mine(),
    withdraw: (uuid: string) => withdraw(uuid),
  },
}));

function row(overrides: Partial<PrivateSessionRequest>): PrivateSessionRequest {
  return {
    uuid: "p-1",
    status: "pending",
    starts_at: "2026-10-10T13:00:00Z",
    duration_minutes: 45,
    timezone: "Asia/Qatar",
    expires_at: "2026-10-09T13:00:00Z",
    decision_reason: null,
    decided_at: null,
    created_at: "2026-10-01T09:00:00Z",
    course: { uuid: "c-1", title: "الفيزياء للصف العاشر" },
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  withdraw.mockResolvedValue({});
});

async function open() {
  await act(async () => {
    render(<MyPrivateSessionsPage />);
  });
}

describe("the student's private-session requests", () => {
  it("reads the student's own list and nothing else", async () => {
    mine.mockResolvedValue({ data: [row({})] });

    await open();

    expect(mine).toHaveBeenCalledTimes(1);
    expect(withdraw).not.toHaveBeenCalled();
    expect(screen.getByText("الفيزياء للصف العاشر")).toBeTruthy();
    expect(screen.getByText("بانتظار ردّ المدرّس")).toBeTruthy();
  });

  /*
  | ⚠️ ON THE VIEWER'S CLOCK, NOT THE ZONE THE ROW CARRIES (2026-09-25). The row's
  | `timezone` is the PLATFORM zone (Asia/Qatar); a student in Cairo reads the
  | lesson on Cairo's clock, with the clock named. November, because in summer
  | the two clocks agree and the case would prove nothing.
  */
  afterEach(() => setStoredViewerTimeZone(null));

  it("formats times on the viewer's own clock, with the clock named", async () => {
    setStoredViewerTimeZone("Africa/Cairo");
    mine.mockResolvedValue({ data: [row({ starts_at: "2026-11-10T15:00:00Z" })] });

    await open();

    const cairo = formatSessionTime("2026-11-10T15:00:00Z", "Africa/Cairo");

    // The two zones must disagree, or this proves nothing about which one ran.
    expect(cairo).not.toBe(formatSessionTime("2026-11-10T15:00:00Z", "Asia/Qatar"));
    expect(screen.getByText((text) => text.includes(`${cairo} (توقيت مصر)`))).toBeTruthy();
  });

  it("shows the teacher's reason on a refusal, and offers no withdraw", async () => {
    mine.mockResolvedValue({
      data: [row({ status: "rejected", decision_reason: "الأحد مشغول، جرّب الإثنين." })],
    });

    await open();

    expect(screen.getByText("مرفوض")).toBeTruthy();
    expect(screen.getByText("الأحد مشغول، جرّب الإثنين.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "سحب الطلب" })).toBeNull();
  });

  it("links an accepted request to the lesson it became", async () => {
    mine.mockResolvedValue({
      data: [row({ status: "accepted", class_session_uuid: "s-9" })],
    });

    await open();

    const link = screen.getByRole("link", { name: "صفحة الحصة" });

    expect(link.getAttribute("href")).toBe("/sessions/s-9");
  });

  it("withdraws only after the window is confirmed, then reloads", async () => {
    mine.mockResolvedValueOnce({ data: [row({})] });
    mine.mockResolvedValueOnce({ data: [row({ status: "withdrawn" })] });

    await open();

    fireEvent.click(screen.getByRole("button", { name: "سحب الطلب" }));

    // The first press asks; it does not act.
    expect(withdraw).not.toHaveBeenCalled();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اسحب الطلب" }));
    });

    expect(withdraw).toHaveBeenCalledTimes(1);
    expect(withdraw).toHaveBeenCalledWith("p-1");
    expect(mine).toHaveBeenCalledTimes(2);
    expect(screen.getByText("مسحوب")).toBeTruthy();
  });

  it("does nothing when the window is cancelled", async () => {
    mine.mockResolvedValue({ data: [row({})] });

    await open();

    fireEvent.click(screen.getByRole("button", { name: "سحب الطلب" }));
    fireEvent.click(screen.getByRole("button", { name: "إلغاء" }));

    expect(withdraw).not.toHaveBeenCalled();
  });

  it("says the request changed, and re-reads it, when the teacher answered first", async () => {
    mine.mockResolvedValueOnce({ data: [row({})] });
    mine.mockResolvedValueOnce({ data: [row({ status: "accepted" })] });
    withdraw.mockRejectedValue(
      new ApiError("Conflict", 409, { message: "تم البتّ في هذا الطلب بالفعل." }),
    );

    await open();

    fireEvent.click(screen.getByRole("button", { name: "سحب الطلب" }));

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "اسحب الطلب" }));
    });

    expect(screen.getByText("لم يُسحب الطلب")).toBeTruthy();
    expect(screen.getByText(/تغيّرت الحالة/)).toBeTruthy();
    // The list is read again, so the row shows the teacher's answer rather than
    // a withdraw button that can no longer work.
    expect(mine).toHaveBeenCalledTimes(2);
    expect(screen.getByText("مقبول")).toBeTruthy();
  });

  it("says so when there are no requests yet, and points somewhere", async () => {
    mine.mockResolvedValue({ data: [] });

    await open();

    expect(screen.getByText("لم تطلب حصة خاصة بعد")).toBeTruthy();
  });

  it("offers a retry when the list cannot be read", async () => {
    mine.mockRejectedValueOnce(new Error("offline"));
    mine.mockResolvedValueOnce({ data: [] });

    await open();

    expect(screen.getByText("تعذّر تحميل البيانات")).toBeTruthy();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /أعد المحاولة|إعادة المحاولة/ }));
    });

    expect(mine).toHaveBeenCalledTimes(2);
    expect(screen.getByText("لم تطلب حصة خاصة بعد")).toBeTruthy();
  });
});
