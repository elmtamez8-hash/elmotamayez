import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

/*
| Extra time / extra days had an endpoint and no screen. What is pinned here:
| the picker offers the teacher's own students once each, the payload carries
| exactly the keys `GrantAccommodationRequest` validates, a 422 lands under its
| field, and a revocation asks before it sends.
*/
const list = vi.fn();
const grant = vi.fn();
const revoke = vi.fn();
const students = vi.fn();
let mockUser: { permissions: string[] } = { permissions: ["accommodations.manage", "billing.balance.view"] };

vi.mock("@/lib/accommodations", () => ({
  accommodations: {
    list: () => list(),
    grant: (payload: unknown) => grant(payload),
    revoke: (uuid: string) => revoke(uuid),
  },
}));
vi.mock("@/lib/billing", () => ({ billing: { students: (page: number) => students(page) } }));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: AccommodationsPage } = await import("./page");

function balance(student: string, name: string, course: string) {
  return { student_uuid: student, student_name: name, course_uuid: course, course_title: "كورس" };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = { permissions: ["accommodations.manage", "billing.balance.view"] };
  list.mockResolvedValue({
    data: [
      {
        uuid: "acc-1",
        student: { uuid: "s-9", name: "سارة" },
        extra_time_pct: 25,
        extended_days: 3,
        reason: "تقرير طبي",
        granted_at: "2026-09-01T10:00:00Z",
      },
    ],
  });
  // One student enrolled in two courses: one row per ENROLMENT on the wire.
  students.mockResolvedValue({
    data: [balance("s-1", "أحمد", "c-1"), balance("s-1", "أحمد", "c-2"), balance("s-2", "منى", "c-1")],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 3, withheld_students: 0 },
  });
});

async function openPage() {
  await act(async () => {
    render(<AccommodationsPage />);
  });
}

function fill() {
  fireEvent.change(screen.getByLabelText(/^الطالب/), { target: { value: "s-1" } });
  fireEvent.change(screen.getByLabelText(/وقت إضافي في الاختبارات/), { target: { value: "50" } });
  fireEvent.change(screen.getByLabelText(/^السبب/), { target: { value: "عسر قراءة موثّق" } });
}

describe("the accommodations screen", () => {
  it("lists what is in force", async () => {
    await openPage();

    expect(screen.getByText("سارة")).toBeTruthy();
    expect(screen.getByText("تقرير طبي")).toBeTruthy();
  });

  it("offers each of the teacher's students once, however many courses they take", async () => {
    await openPage();

    const options = Array.from((screen.getByLabelText(/^الطالب/) as HTMLSelectElement).options).map(
      (option) => option.value,
    );

    expect(options.filter((value) => value === "s-1")).toHaveLength(1);
    expect(options).toContain("s-2");
  });

  it("sends exactly the keys the form request validates", async () => {
    grant.mockResolvedValue({ data: { uuid: "acc-2", extra_time_pct: 50, extended_days: 0 } });
    await openPage();
    fill();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ الترتيب" }));
    });

    expect(grant).toHaveBeenCalledTimes(1);
    expect(grant.mock.calls[0][0]).toEqual({
      student_uuid: "s-1",
      extra_time_pct: 50,
      extended_days: 0,
      reason: "عسر قراءة موثّق",
    });
  });

  it("puts a validation error under the field it names", async () => {
    grant.mockRejectedValue(
      new ApiError("تحقّق من الحقول", 422, { errors: { reason: ["السبب قصير جداً."] } }),
    );
    await openPage();
    fill();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "احفظ الترتيب" }));
    });

    expect(document.getElementById("reason-error")?.textContent).toBe("السبب قصير جداً.");
  });

  it("asks before revoking, and revokes once on confirmation", async () => {
    revoke.mockResolvedValue({ data: { uuid: "acc-1", revoked: true } });
    await openPage();

    fireEvent.click(screen.getByRole("button", { name: /^إلغاء الترتيب/ }));
    expect(revoke).not.toHaveBeenCalled();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "ألغِ الترتيب" }));
    });

    expect(revoke).toHaveBeenCalledTimes(1);
    expect(revoke).toHaveBeenCalledWith("acc-1");
  });

  it("does not ask for a list the reader cannot read", async () => {
    mockUser = { permissions: ["accommodations.manage"] };
    await openPage();

    expect(students).not.toHaveBeenCalled();
    expect(screen.queryByRole("button", { name: "احفظ الترتيب" })).toBeNull();
  });
});
