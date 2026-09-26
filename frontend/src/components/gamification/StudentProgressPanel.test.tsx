import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { StudentProgressPanel } from "./StudentProgressPanel";

const student = vi.fn();
let permissions: string[] = [];

vi.mock("@/lib/gamification", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/gamification")>()),
  gamification: {
    student: (...a: unknown[]) => student(...a),
  },
}));

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: { permissions } }) };
});

const PROGRESS = {
  xp: 340,
  level: 3,
  level_name: "مثابر",
  next_level_xp: 500,
  current_streak: 4,
  best_streak: 9,
  shields: 1,
  badges: [{ key: "first-exam", name: "أول اختبار", icon: null, awarded_at: null }],
  coin_balances: [{ workspace_uuid: "w-other", teacher_name: "أكاديمية أخرى", coins: 77 }],
};

beforeEach(() => {
  vi.clearAllMocks();
  permissions = ["progress.view.student"];
});

async function mount() {
  await act(async () => {
    render(<StudentProgressPanel studentUuid="u-sara" />);
  });
}

describe("StudentProgressPanel", () => {
  it("reads the student's progress and shows level, points and badges — never another teacher's coins", async () => {
    student.mockResolvedValue(PROGRESS);

    await mount();

    expect(student).toHaveBeenCalledWith("u-sara");
    expect(screen.getByText("مثابر")).toBeTruthy();
    expect(screen.getByText("340")).toBeTruthy();
    expect(screen.getByText("أول اختبار")).toBeTruthy();
    expect(screen.queryByText(/أكاديمية أخرى/)).toBeNull();
    expect(screen.queryByText("77")).toBeNull();
  });

  it("explains a missing permission and makes no request", async () => {
    permissions = [];

    await mount();

    expect(screen.getByText("تقدّم الطالب غير متاح لحسابك")).toBeTruthy();
    expect(student).not.toHaveBeenCalled();
  });

  it("shows the refusal when the student is not the reader's", async () => {
    student.mockRejectedValue(new ApiError("raw", 403, { message: "raw" }));

    await mount();

    expect(screen.getByText("تعذّر تحميل تقدّم الطالب")).toBeTruthy();
  });
});
