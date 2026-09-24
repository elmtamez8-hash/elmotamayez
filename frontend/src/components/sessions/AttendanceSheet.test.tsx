import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AttendanceSheet } from "./AttendanceSheet";
import type { AttendanceRow } from "@/lib/class-sessions";

/*
| The note box starts from what the teacher already wrote.
|
| It started empty on every visit, and saving from it posted that empty note —
| erasing the remark the guardian's report was about to carry.
*/

const feedback = vi.fn();

vi.mock("@/lib/class-sessions", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/class-sessions")>()),
  attendance: {
    list: vi.fn(),
    override: vi.fn(),
    feedback: (...args: unknown[]) => feedback(...args),
  },
}));

const ROW: AttendanceRow = {
  uuid: "att-1",
  status: "present",
  status_label: "حاضر",
  source: "automatic",
  source_label: "آلي",
  auto_status: "present",
  auto_status_label: "حاضر",
  first_joined_at: null,
  stay_seconds: 3000,
  was_overridden: false,
  override_reason: null,
  overridden_at: null,
  recording_watched_at: null,
  student: { uuid: "stu-1", name: "سارة" },
  feedback: { rating: 4, note: "مشاركة ممتازة" },
};

beforeEach(() => {
  vi.clearAllMocks();
  feedback.mockResolvedValue({ data: [] });
});

describe("the teacher's remark", () => {
  it("opens with the saved note and re-sends it untouched, never a rating", async () => {
    render(<AttendanceSheet rows={[ROW]} canOverride={false} sessionUuid="s-1" />);

    expect((screen.getByLabelText("ملاحظة المدرّس") as HTMLInputElement).value).toBe("مشاركة ممتازة");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حفظ الملاحظة" }));
    });

    expect(feedback).toHaveBeenCalledWith("s-1", [{ student_uuid: "stu-1", note: "مشاركة ممتازة" }]);
  });

  it("sends the teacher's edit once they type one", async () => {
    render(<AttendanceSheet rows={[ROW]} canOverride={false} sessionUuid="s-1" />);

    fireEvent.change(screen.getByLabelText("ملاحظة المدرّس"), { target: { value: "تحسّن واضح" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حفظ الملاحظة" }));
    });

    expect(feedback).toHaveBeenCalledWith("s-1", [{ student_uuid: "stu-1", note: "تحسّن واضح" }]);
  });
});
