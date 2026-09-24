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

  it("sends the child's account code when one is entered", async () => {
    post.mockResolvedValue(created());

    render(<AddChildForm schoolYears={[]} />);

    fireEvent.change(screen.getByLabelText("اسم الطالب"), { target: { value: "كريم" } });
    fireEvent.change(screen.getByLabelText(/رمز حساب الطالب/), {
      target: { value: " 9f1c2d3e-0000-4000-8000-000000000001 " },
    });
    fireEvent.click(screen.getByRole("button", { name: "إضافة الطفل" }));

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(
        "/family/relations",
        expect.objectContaining({ student_uuid: "9f1c2d3e-0000-4000-8000-000000000001" }),
      ),
    );
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
