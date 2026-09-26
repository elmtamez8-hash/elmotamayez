import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";
import { CohortRoster } from "./CohortRoster";

/*
| «إضافة طالب» و«إخراج» على قائمة المجموعة — نقطتا `POST` و`DELETE
| /manage/cohorts/{cohort}/members` اللتان لم يستدعِهما ملفٌّ في الواجهة.
*/

const members = vi.fn();
const eligibleStudents = vi.fn();
const addMember = vi.fn();
const removeMember = vi.fn();
const studentHistory = vi.fn();

vi.mock("@/lib/cohorts", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/cohorts")>()),
  manageCohorts: {
    members: (...a: unknown[]) => members(...a),
    eligibleStudents: (...a: unknown[]) => eligibleStudents(...a),
    addMember: (...a: unknown[]) => addMember(...a),
    removeMember: (...a: unknown[]) => removeMember(...a),
    studentHistory: (...a: unknown[]) => studentHistory(...a),
  },
}));

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: { permissions: [] } }) };
});

const SARA = { uuid: "u-sara", name: "سارة علي", joined_at: "2026-09-01T10:00:00Z" };

beforeEach(() => {
  vi.clearAllMocks();
  members.mockResolvedValue({ data: [SARA] });
  eligibleStudents.mockResolvedValue({
    data: [
      { uuid: "u-omar", name: "عمر خالد", current_cohort: null },
      { uuid: "u-huda", name: "هدى سالم", current_cohort: { uuid: "g-2", name: "مجموعة الأحد" } },
    ],
  });
  addMember.mockResolvedValue({ message: "ok" });
  removeMember.mockResolvedValue(undefined);
  studentHistory.mockResolvedValue({ data: [] });
});

async function mount(props: Partial<Parameters<typeof CohortRoster>[0]> = {}) {
  await act(async () => {
    render(<CohortRoster cohortUuid="g-1" courseUuid="c-1" archived={false} {...props} />);
  });
}

describe("CohortRoster", () => {
  it("lists the group's students from the group's own roster", async () => {
    await mount();

    expect(members).toHaveBeenCalledWith("g-1");
    expect(await screen.findByText("سارة علي")).toBeTruthy();
  });

  it("adds a student picked from the server's eligible list, and says one is a move", async () => {
    const onChanged = vi.fn();
    await mount({ onChanged });

    fireEvent.click(screen.getByRole("button", { name: /إضافة طالب/ }));

    await waitFor(() => expect(eligibleStudents).toHaveBeenCalledWith("g-1"));
    expect(await screen.findByRole("option", { name: "هدى سالم — يُنقَل من «مجموعة الأحد»" })).toBeTruthy();

    fireEvent.change(screen.getByLabelText("الطالب"), { target: { value: "u-omar" } });
    fireEvent.change(screen.getByLabelText("السبب (اختياري)"), { target: { value: "طلب وليّ الأمر" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أضِف" }));
    });

    expect(addMember).toHaveBeenCalledWith("g-1", "u-omar", "طلب وليّ الأمر");
    expect(await screen.findByText("أُضيف عمر خالد إلى المجموعة.")).toBeTruthy();
    expect(onChanged).toHaveBeenCalled();
    // The roster is read again, so the new row appears without a reload.
    expect(members).toHaveBeenCalledTimes(2);
  });

  it("shows the server's capacity refusal", async () => {
    addMember.mockRejectedValue(
      new ApiError("اكتملت مقاعد هذه المجموعة.", 422, {
        message: "اكتملت مقاعد هذه المجموعة.",
        code: "cohort_full",
      }),
    );
    await mount();

    fireEvent.click(screen.getByRole("button", { name: /إضافة طالب/ }));
    await screen.findByRole("option", { name: "عمر خالد" });
    fireEvent.change(screen.getByLabelText("الطالب"), { target: { value: "u-omar" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أضِف" }));
    });

    expect(await screen.findByText("اكتملت مقاعد هذه المجموعة.")).toBeTruthy();
  });

  it("speaks to the teacher, not the student, when the refusal was written for the student", async () => {
    addMember.mockRejectedValue(
      new ApiError("أنت في هذه المجموعة بالفعل.", 422, {
        message: "أنت في هذه المجموعة بالفعل.",
        code: "same_cohort",
      }),
    );
    await mount();

    fireEvent.click(screen.getByRole("button", { name: /إضافة طالب/ }));
    await screen.findByRole("option", { name: "عمر خالد" });
    fireEvent.change(screen.getByLabelText("الطالب"), { target: { value: "u-omar" } });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "أضِف" }));
    });

    expect(await screen.findByText("هذا الطالب في هذه المجموعة بالفعل.")).toBeTruthy();
    expect(screen.queryByText("أنت في هذه المجموعة بالفعل.")).toBeNull();
  });

  it("takes two presses to remove a student", async () => {
    await mount();
    await screen.findByText("سارة علي");

    fireEvent.click(screen.getByRole("button", { name: "إخراج" }));
    expect(removeMember).not.toHaveBeenCalled();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تأكيد إخراج سارة علي" }));
    });

    expect(removeMember).toHaveBeenCalledWith("g-1", "u-sara");
    expect(await screen.findByText("أُخرِج سارة علي من المجموعة.")).toBeTruthy();
  });

  it("shows a refused removal instead of swallowing it", async () => {
    removeMember.mockRejectedValue(
      new ApiError("هذا الطالب ليس في هذه المجموعة.", 422, {
        message: "هذا الطالب ليس في هذه المجموعة.",
      }),
    );
    await mount();
    await screen.findByText("سارة علي");

    fireEvent.click(screen.getByRole("button", { name: "إخراج" }));
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تأكيد إخراج سارة علي" }));
    });

    expect(await screen.findByText("هذا الطالب ليس في هذه المجموعة.")).toBeTruthy();
  });

  it("offers no add on an archived group, which the writer refuses", async () => {
    await mount({ archived: true });

    await screen.findByText("سارة علي");
    expect(screen.queryByRole("button", { name: /إضافة طالب/ })).toBeNull();
  });

  it("opens a member's group history and progress from the row", async () => {
    await mount();
    await screen.findByText("سارة علي");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /سجل المجموعات/ }));
    });
    expect(studentHistory).toHaveBeenCalledWith("c-1", "u-sara");

    fireEvent.click(screen.getByRole("button", { name: /التقدّم/ }));
    // This reader holds no `progress.view.student`: an explanation, not a 403.
    expect(await screen.findByText("تقدّم الطالب غير متاح لحسابك")).toBeTruthy();
  });
});
