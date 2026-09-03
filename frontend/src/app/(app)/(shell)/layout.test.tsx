import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { User } from "@/lib/types";

import ShellLayout from "./layout";

/*
| ⚠️ THE SIDEBAR HAD NEVER BEEN RENDERED BY A TEST, and the half that was missing
| is the half that shipped broken. Every teacher gate is walked by Playwright —
| «بنك الأسئلة» absent for a student, «لوحة التصحيح» absent for a student — and
| the OPPOSITE direction was walked by nothing at all. So a teacher's sidebar
| carried «دفتر أخطائي», «درّب نفسك», «متجر المكافآت», «دعوة صديق» and eleven
| more, and the suite was green.
|
| ⚠️ AND IT COULD NOT HAVE BEEN A PERMISSION. A real student is a member of no
| workspace, so they hold zero permissions — which is exactly why their own
| screens are ungated, and exactly why nothing hid them from anybody else. The
| predicate is `platform_role`, and `isLearner` is imported REAL here rather than
| faked: a stubbed predicate would turn every assertion below into a claim about
| the stub.
|
| ⚠️ BOTH DIRECTIONS IN BOTH FIXTURES, or this file measures a wall. A test that
| only asserts absence passes just as happily against an empty sidebar — the US6
| lesson this repository has already paid for once.
*/

const TEACHER_LABELS = ["حصصي", "بنك الأسئلة", "الواجبات", "لوحة التصحيح"];
const LEARNER_LABELS = ["دفتر أخطائي", "درّب نفسك", "واجباتي", "رصيدي", "متجر المكافآت", "دعوة صديق", "الطلبات"];

function account(overrides: Partial<User>): User {
  return {
    uuid: "u-1",
    first_name: "سلمى",
    last_name: "أحمد",
    name: "سلمى أحمد",
    email: "salma@example.test",
    email_verified_at: null,
    status: "active",
    is_super_admin: false,
    platform_role: null,
    last_workspace_id: null,
    permissions: [],
    created_at: "2026-01-01T00:00:00Z",
    ...overrides,
  } as User;
}

let currentUser: User = account({});

// `importActual` for everything but `useAuth` — `isLearner` is the thing under
// test and must be the real one.
vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: currentUser, loading: false, logout: vi.fn() }) };
});

vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

vi.mock("@/lib/platform-context", () => ({ usePlatformName: () => "المتميز" }));

// The badge count fires for anyone holding `grading.perform`; a real fetch here
// would make this a network test.
vi.mock("@/lib/grading", () => ({
  grading: { queue: () => Promise.resolve({ meta: { total: 0 } }) },
}));

vi.mock("@/components/app/NotificationBell", () => ({ NotificationBell: () => null }));
vi.mock("@/components/app/ServiceWorkerRegistrar", () => ({ ServiceWorkerRegistrar: () => null }));

function navLabels(): string[] {
  const nav = screen.getByRole("navigation", { name: "التنقّل الرئيسي" });

  return Array.from(nav.querySelectorAll("a"), (link) => link.textContent?.trim() ?? "");
}

beforeEach(() => {
  currentUser = account({});
});

afterEach(() => cleanup());

describe("الشريط الجانبي يفصل شاشات المدرّس عن شاشات الطالب", () => {
  it("لا يعرض للمدرّس شاشات الطالب، ويعرض له أدواته", () => {
    currentUser = account({
      platform_role: "teacher",
      last_workspace_id: 1,
      permissions: ["sessions.manage", "bank.view", "assignments.manage", "grading.perform"],
    });

    render(<ShellLayout>محتوى</ShellLayout>);

    const labels = navLabels();

    // The positive half first: without it, every absence below is true of a
    // sidebar that never rendered.
    for (const label of TEACHER_LABELS) expect(labels).toContain(label);
    for (const label of LEARNER_LABELS) expect(labels).not.toContain(label);
  });

  it("يعرض للطالب شاشاته، ولا يعرض له أدوات المدرّس", () => {
    // ⚠️ NO PERMISSIONS AND NO WORKSPACE — the shape a real student actually
    // has. A fixture that stamps either one is measuring somebody who does not
    // exist on this platform.
    currentUser = account({ platform_role: "student" });

    render(<ShellLayout>محتوى</ShellLayout>);

    const labels = navLabels();

    for (const label of LEARNER_LABELS) expect(labels).toContain(label);
    for (const label of TEACHER_LABELS) expect(labels).not.toContain(label);
  });

  it("وليُّ الأمر يقرأ تقارير ابنه من شاشات الطالب نفسها", () => {
    /*
     * ⚠️ THE CASE A «هل أنت موظّف؟» PREDICATE WOULD HAVE BROKEN. A guardian
     * holds zero permissions exactly like a student, so hiding the learner block
     * from everyone-who-is-not-staff would have taken «كشف التقديرات» and
     * «تقييماتي الدورية» away from the one reader those two screens name in
     * their own comments. Asking «do you learn here» keeps them.
     */
    currentUser = account({ platform_role: "parent" });

    const labels = (render(<ShellLayout>محتوى</ShellLayout>), navLabels());

    expect(labels).toContain("كشف التقديرات");
    expect(labels).toContain("تقييماتي الدورية");
    expect(labels).not.toContain("بنك الأسئلة");
  });

  it("المدرّس الذي لم يُنشئ مساحةَ عمل بعدُ ليس طالباً", () => {
    /*
     * ⚠️ THE EDGE THAT RULES OUT EVERY PERMISSION-SHAPED PREDICATE.
     * `RegisterTeacher` «creates no workspace membership and grants no role
     * (FR-010)», so this account's `permissions` is empty — identical to a
     * student's. `can(user, P.membersView)` and `can(user, P.settlementStatement)`
     * both read it as a learner and would hand it the notebook and the shop.
     * `platform_role` survives having no workspace, which is the whole reason it
     * is the predicate.
     */
    currentUser = account({ platform_role: "teacher" });

    const labels = (render(<ShellLayout>محتوى</ShellLayout>), navLabels());

    for (const label of LEARNER_LABELS) expect(labels).not.toContain(label);
  });

  it("يُبقي الشاشات المشتركة لكلا الطرفين", () => {
    // Neither side owns these, and a split that swallowed one of them would be
    // the same defect wearing the other face.
    const shared = ["لوحة التحكم", "الرسائل", "الإشعارات", "خصوصيّتي"];

    currentUser = account({ platform_role: "student" });
    const learner = (render(<ShellLayout>محتوى</ShellLayout>), navLabels());
    cleanup();

    currentUser = account({ platform_role: "teacher", permissions: ["sessions.manage"] });
    const teacher = (render(<ShellLayout>محتوى</ShellLayout>), navLabels());

    for (const label of shared) {
      expect(learner).toContain(label);
      expect(teacher).toContain(label);
    }
  });
});
