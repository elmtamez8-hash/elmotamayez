import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import DashboardPage from "./page";

/*
| «لوحة التحكم» — أوّلُ شاشةٍ بعدَ تسجيلِ الدخول، **ولا صلاحيةَ عليها في القائمة**:
| يصلُها الطالبُ ووليُّ الأمرِ والمدرّسُ والمساعد. فكلُّ قراءةٍ فيها يجبُ أن تكون
| قراءةً يملكُها الجميع.
|
| ⚠️ وكانت لا تفعل. الكرتُ الرابعُ ينادي `/courses` — فهرسَ التأليف، و
| `CoursePolicy::viewAny()` يشترطُ `courses.view`، وهي صلاحيةُ مساحةِ عمل: الطالبُ
| عضوٌ في لا مساحة، فسياقُه `null`، ومُعرِّفُ فريقِ spatie `null`، وكلُّ `can()`
| تحتَه false. ٤٠٣ لكلِّ طالبٍ ولكلِّ وليِّ أمرٍ على المنصّة — والقراءاتُ الثلاثُ
| السليمةُ تشاركُه `Promise.all` واحداً، فسقطت اللوحةُ **كلُّها** إلى «تعذّر تحميل
| البيانات» منذُ شُحِنت.
|
| الاختبارُ يقيسُ المسارَ المطلوبَ لا الشاشةَ وحدَها: شاشةٌ خضراءُ على قاعدةٍ
| تُجيبُ كلَّ نداءٍ بنجاحٍ لا تقولُ شيئاً عن أيِّ بابٍ طُرِق.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

// `importActual` for everything but `useAuth`: this page branches on
// `isLearner()` to aim the certificates panel at the learner's screen or the
// teacher's, and a wholesale mock would replace the predicate under test with
// nothing.
vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: { first_name: "سلمى", platform_role: "student" } }) };
});

function answer(path: string) {
  if (path.startsWith("/enrollments")) return Promise.resolve({ data: [] });
  if (path.startsWith("/certificates")) return Promise.resolve({ data: [] });
  if (path.startsWith("/marketplace/courses")) {
    return Promise.resolve({ data: [], meta: { total: 37 } });
  }
  // كلُّ ما عدا ذلك يُرفَض كما يرفضُه الخادمُ لطالب.
  return Promise.reject(new Error("403"));
}

beforeEach(() => {
  vi.clearAllMocks();
  get.mockImplementation(answer);
});

describe("DashboardPage", () => {
  it("asks no endpoint a student is refused", async () => {
    render(<DashboardPage />);

    expect(await screen.findByText("أهلاً بعودتك، سلمى")).toBeDefined();

    const paths: string[] = get.mock.calls.map((c) => c[0] as string);

    // الفهرسُ المحميُّ نفسُه، لا أيُّ مسارٍ يبدأُ به: `/marketplace/courses`
    // مسموحٌ ويحتوي الكلمةَ نفسَها.
    expect(paths).not.toContain("/courses");
    expect(paths.some((p) => p.startsWith("/marketplace/courses"))).toBe(true);
  });

  it("draws the total, not the size of one page", async () => {
    render(<DashboardPage />);

    // الرقمُ يأتي من `meta.total`. القراءةُ القديمةُ كانت `data.length` على
    // صفحةٍ من خمسةَ عشرَ — رقمٌ يتوقّفُ عند 15 مهما بلغَ الكتالوج.
    expect(await screen.findByText("37")).toBeDefined();
  });

  it("shows the error state when a read it does need fails", async () => {
    get.mockImplementation((path: string) =>
      path.startsWith("/enrollments") ? Promise.reject(new Error("500")) : answer(path),
    );

    render(<DashboardPage />);

    // العطلُ الحقيقيُّ ما زال عطلاً — الإصلاحُ ليس ابتلاعَ الرفض.
    expect(await screen.findByText("تعذّر تحميل البيانات")).toBeDefined();
  });
});
