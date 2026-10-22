import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TeacherStartCard } from "./TeacherStartCard";

/*
| A teacher approved a minute ago met nine empty cards, published a course nobody
| could buy, and was never told a plan waits for the platform's price (journey
| audit 2026-10-21). Each step is ticked from their own data; the card leaves
| once a published course can be bought.
*/
const get = vi.fn();
vi.mock("@/lib/api", () => ({ api: { get: (path: string) => get(path) } }));

let mockUser: Record<string, unknown> | null = null;
vi.mock("@/lib/auth-context", async () => ({
  ...(await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context")),
  useAuth: () => ({ user: mockUser }),
}));

function answer(courses: unknown[], plans: unknown[]) {
  get.mockImplementation((path: string) =>
    Promise.resolve({ data: path.startsWith("/manage/plans") ? plans : courses }),
  );
}

beforeEach(() => {
  get.mockReset();
  mockUser = { permissions: ["courses.create", "plans.manage", "roles.manage"], workspaces: [{ uuid: "w" }] };
});

describe("TeacherStartCard", () => {
  it("ticks what is done and keeps the platform's price as the step still open", async () => {
    answer([{ uuid: "c-1", status: "published", is_free: false }], [{ is_active: true, is_priced: false }]);

    render(<TeacherStartCard />);

    expect(await screen.findByText("ابدأ من هنا")).toBeTruthy();
    expect(screen.getAllByText(/· تمّت/)).toHaveLength(3);
    expect(screen.getByText("تسعير المنصّة")).toBeTruthy();
  });

  it("leaves once a published course can be bought", async () => {
    answer([{ uuid: "c-1", status: "published", is_free: false }], [{ is_active: true, is_priced: true }]);

    const { container } = render(<TeacherStartCard />);
    await vi.waitFor(() => expect(get).toHaveBeenCalledTimes(2));

    expect(container.textContent).toBe("");
  });

  it("never draws for an assistant, who creates no course", () => {
    mockUser = { permissions: ["courses.update"], workspaces: [{ uuid: "w" }] };

    const { container } = render(<TeacherStartCard />);

    expect(container.textContent).toBe("");
    expect(get).not.toHaveBeenCalled();
  });

  // Co-teacher journey 2026-10-22: a co-teacher holds both of the owner's other
  // permissions, and read the owner's courses as «أنشئ كورسك الأول · تمّت».
  it("never draws for a co-teacher, whose steps are the owner's", () => {
    mockUser = { permissions: ["courses.create", "plans.manage"], workspaces: [{ uuid: "w" }] };

    const { container } = render(<TeacherStartCard />);

    expect(container.textContent).toBe("");
    expect(get).not.toHaveBeenCalled();
  });
});
