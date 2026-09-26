import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { StudentCohortHistory } from "./StudentCohortHistory";

const studentHistory = vi.fn();

vi.mock("@/lib/cohorts", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/cohorts")>()),
  manageCohorts: {
    studentHistory: (...a: unknown[]) => studentHistory(...a),
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
});

async function mount() {
  await act(async () => {
    render(<StudentCohortHistory courseUuid="c-1" studentUuid="u-sara" />);
  });
}

describe("StudentCohortHistory", () => {
  it("reads the student's history in this course and shows who moved them and why", async () => {
    studentHistory.mockResolvedValue({
      data: [
        {
          uuid: "e-2",
          event: "transferred",
          reason: "تعارض مع موعد المدرسة",
          created_at: "2026-09-10T10:00:00Z",
          cohort: { uuid: "g-2", name: "مجموعة الأحد" },
          from_cohort: { uuid: "g-1", name: "مجموعة السبت" },
          actor: { uuid: "t-1", name: "أ. خالد" },
        },
      ],
    });

    await mount();

    expect(studentHistory).toHaveBeenCalledWith("c-1", "u-sara");
    expect(screen.getByText(/انتقل — «مجموعة الأحد» من «مجموعة السبت»/)).toBeTruthy();
    expect(screen.getByText("تعارض مع موعد المدرسة")).toBeTruthy();
    expect(screen.getByText(/بيد أ\. خالد/)).toBeTruthy();
  });

  it("says so when there is nothing yet", async () => {
    studentHistory.mockResolvedValue({ data: [] });

    await mount();

    expect(screen.getByText("لا حركة لهذا الطالب بين مجموعات الكورس بعد.")).toBeTruthy();
  });

  it("shows a refusal instead of an empty history", async () => {
    studentHistory.mockRejectedValue(new ApiError("raw", 403, { message: "raw" }));

    await mount();

    expect(screen.getByText("تعذّر تحميل سجل المجموعات")).toBeTruthy();
    expect(screen.queryByText("لا حركة لهذا الطالب بين مجموعات الكورس بعد.")).toBeNull();
  });
});
