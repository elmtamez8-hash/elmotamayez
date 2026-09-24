import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AddChildForm } from "./AddChildForm";

/*
| The parent-signup half of «link a child who already has an account». Like
| `/family`, this form used to send `student_name` alone, so every child a new
| parent named became a name-only row with no account behind it.
*/

const get = vi.fn();
const post = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string, body: unknown) => post(path, body),
  },
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));


function created(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "r-1",
    student_name: "كريم",
    student_age: null,
    student_school_year_name: null,
    ...overrides,
  };
}

describe("AddChildForm", () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
    get.mockResolvedValue({ data: [] });
  });

  /*
   * ⛔ Owner decision (2026-09-24): a child who already has an account is named
   * by the CODE alone. The server fills the name, age and year from the
   * account when the child accepts, so the form neither asks for them nor
   * sends them — the payload is exactly the code, the relation and the
   * permissions.
   */
  it("hides name, age and year once a code is typed, and sends the code alone", async () => {
    post.mockResolvedValue(created({ student_name: "", status: "pending" }));

    render(<AddChildForm schoolYears={[{ slug: "year-10", name: "الصف العاشر" } as never]} />);

    expect(screen.getByLabelText("اسم الطالب")).toBeTruthy();

    fireEvent.change(screen.getByLabelText(/رمز حساب الطالب/), {
      target: { value: " 9f1c2d3e-0000-4000-8000-000000000001 " },
    });

    expect(screen.queryByLabelText("اسم الطالب")).toBeNull();
    expect(screen.queryByLabelText("العمر")).toBeNull();
    expect(screen.queryByLabelText("الصف الدراسي")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "إضافة الطفل" }));

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][0]).toBe("/family/relations");
    expect(Object.keys(post.mock.calls[0][1] as object).sort()).toEqual(
      ["permissions", "relation_type", "student_uuid"],
    );
    expect((post.mock.calls[0][1] as { student_uuid: string }).student_uuid).toBe(
      "9f1c2d3e-0000-4000-8000-000000000001",
    );

    // The pending row carries no name until the child accepts — the list says
    // what it is rather than printing a blank.
    expect(await screen.findByText("طلب ربط بانتظار موافقة الطالب")).toBeTruthy();
  });

  it("omits the code for a child with no account", async () => {
    post.mockResolvedValue(created());

    render(<AddChildForm schoolYears={[]} />);

    fireEvent.change(screen.getByLabelText("اسم الطالب"), { target: { value: "كريم" } });
    fireEvent.click(screen.getByRole("button", { name: "إضافة الطفل" }));

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][1]).not.toHaveProperty("student_uuid");
  });

  it("points to the working preferences screen and calls no removed route", async () => {
    // `/parent/notification-preferences` is gone (404), and the block that read it
    // hid itself — and its error — whenever the read failed.
    render(<AddChildForm schoolYears={[]} />);

    const link = screen.getByRole("link", { name: "إعدادات التنبيهات" });

    expect(link.getAttribute("href")).toBe("/settings/notifications");
    expect(get.mock.calls.map(([path]) => path)).not.toContain("/parent/notification-preferences");
  });
});
