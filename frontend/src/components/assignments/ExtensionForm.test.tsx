import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import { ExtensionForm } from "./ExtensionForm";

const extend = vi.fn();
const picker = vi.fn();

vi.mock("@/lib/assignments", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/assignments")>()),
  assignments: { extend: (...args: unknown[]) => extend(...args) },
}));

vi.mock("@/lib/use-teacher-students", () => ({
  useTeacherStudents: () => picker(),
}));

const NOW = new Date("2026-09-25T12:00:00");

beforeEach(() => {
  vi.clearAllMocks();
  extend.mockResolvedValue({ data: {} });
  picker.mockReturnValue({
    canPick: true,
    students: [{ uuid: "s-1", name: "سارة" }],
    failed: false,
  });
});

function fill(until: string) {
  fireEvent.change(screen.getByLabelText(/الطالب/), { target: { value: "s-1" } });
  fireEvent.change(screen.getByLabelText(/الموعد الجديد/), { target: { value: until } });
}

describe("ExtensionForm", () => {
  it("sends the chosen student and the deadline as an absolute instant", async () => {
    const onGranted = vi.fn();

    render(<ExtensionForm assignmentUuid="as-1" onGranted={onGranted} now={() => NOW} />);

    fill("2026-09-28T18:30");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "امنح المهلة" }));
    });

    expect(extend).toHaveBeenCalledWith("as-1", {
      student_uuid: "s-1",
      until: new Date("2026-09-28T18:30").toISOString(),
    });
    expect(onGranted).toHaveBeenCalledOnce();
    expect(screen.getByText("مُنح سارة مهلةً جديدة.")).toBeTruthy();
  });

  it("refuses a deadline that has already passed without asking the server", async () => {
    render(<ExtensionForm assignmentUuid="as-1" onGranted={vi.fn()} now={() => NOW} />);

    fill("2026-09-25T09:00");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "امنح المهلة" }));
    });

    expect(extend).not.toHaveBeenCalled();
    expect(screen.getByText("اختر موعداً لم يمضِ بعد.")).toBeTruthy();
  });

  it("says why a 404 happened instead of printing it", async () => {
    extend.mockRejectedValue(new ApiError("Not Found", 404, null));

    render(<ExtensionForm assignmentUuid="as-1" onGranted={vi.fn()} now={() => NOW} />);

    fill("2026-09-28T18:30");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "امنح المهلة" }));
    });

    expect(screen.getByText(/لم يعد مسجّلاً عندك/)).toBeTruthy();
  });

  it("explains itself to an account that cannot read the student list", () => {
    picker.mockReturnValue({ canPick: false, students: null, failed: false });

    render(<ExtensionForm assignmentUuid="as-1" onGranted={vi.fn()} now={() => NOW} />);

    expect(screen.getByText("منح المهلة غير متاح لحسابك")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "امنح المهلة" })).toBeNull();
  });
});
