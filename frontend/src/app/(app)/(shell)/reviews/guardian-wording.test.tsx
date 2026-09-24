import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ReportCardsPage from "../report-cards/page";
import MyReviewsPage from "./page";

/*
| «تقييماتي الدورية» and «كشف التقديرات» — each one screen for two readers.
|
| A guardian reported both pages addressing them as the student («ما كتبه
| مدرّسوك عن التزامك» · «تقديرك في كلّ فترة»). Who is reading is decided by the
| same predicate as the sidebar (`dashboardAudience`), and each page is measured
| here from BOTH sides so neither sentence can drift back to the other reader.
*/

const get = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: vi.fn(),
  },
}));

let mockUser: Record<string, unknown> | null = null;

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

const activeChild = {
  uuid: "r-1",
  relation_type: "parent",
  relation_type_label: "وليّ أمر",
  status: "active",
  status_label: "نشط",
  student_name: "كريم",
  student_uuid: "c-1",
  student_has_account: true,
  viewer_side: "guardian",
  can_decide: false,
  permissions: [],
};

describe("guardian wording on the child's screens", () => {
  beforeEach(() => {
    get.mockReset();
    get.mockImplementation((path: string) =>
      Promise.resolve(path.startsWith("/family/relations") ? { data: [activeChild] } : { data: [] }),
    );
  });

  it("reviews: a student reads about themselves", async () => {
    mockUser = { uuid: "s-1", platform_role: "student", permissions: [] };

    render(<MyReviewsPage />);

    expect(await screen.findByText(/ما كتبه مدرّسوك عن التزامك/)).toBeTruthy();
    expect(await screen.findByText("يظهر هنا تقييم مدرّسك الدوري فور نشره.")).toBeTruthy();
  });

  it("reviews: a guardian reads about their child", async () => {
    mockUser = { uuid: "p-1", platform_role: "parent", permissions: [] };

    render(<MyReviewsPage />);

    expect(await screen.findByText(/التزام ابنك/)).toBeTruthy();
    expect(await screen.findByText("يظهر هنا تقييم المدرّس الدوري لابنك فور نشره.")).toBeTruthy();
    expect(screen.queryByText(/مدرّسوك|مدرّسك/)).toBeNull();
  });

  it("report cards: a student reads their own grade", async () => {
    mockUser = { uuid: "s-1", platform_role: "student", permissions: [] };

    render(<ReportCardsPage />);

    expect(await screen.findByText(/^تقديرك في كلّ فترة/)).toBeTruthy();
  });

  it("report cards: a guardian reads the child's", async () => {
    mockUser = { uuid: "p-1", platform_role: "parent", permissions: [] };

    render(<ReportCardsPage />);

    expect(await screen.findByText(/^تقدير ابنك في كلّ فترة/)).toBeTruthy();
    expect(screen.queryByText(/^تقديرك/)).toBeNull();
  });
});
