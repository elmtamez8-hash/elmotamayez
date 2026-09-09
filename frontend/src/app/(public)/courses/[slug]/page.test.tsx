import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { NotFoundError, type CourseDetail } from "@/lib/public-api";

/*
| 027 · T078 — A FAILED READ AND AN EMPTY ONE MUST NOT READ THE SAME.
|
| `loadAvailability()` used to answer `[]` out of its own `catch`, so a
| stumbling API drew «لم يعلن المدرّس مواعيد متاحة بعد» — a sentence stating, as
| a fact about the teacher, something the page never found out. The student then
| waits for an announcement that was made weeks ago.
|
| ⚠️ THE GUARD IS THE DIFFERENCE, NOT EITHER STRING. Each case asserts the
| other's copy is ABSENT: an implementation that renders both states as the same
| EmptyState passes any test that only looks for one of them.
|
| ⚠️ AND IT IS TESTED AT THE PAGE, NOT AT THE FUNCTION. `loadAvailability` is
| private, and a unit test of it would pass against a page that maps both
| answers onto one component.
*/

const teacher = vi.fn();

/*
| ⚠️ زائرٌ غيرُ مسجَّلٍ هو الحالةُ الحقيقيّةُ لهذه الصفحة، و`AuthProvider` يعيشُ
| في التخطيطِ الجذريِّ لا في `(public)` — فتصييرُ الصفحةِ وحدَها هنا بلا سياقٍ
| يرمي `useAuth` عندَ أوّلِ مكوّنٍ يقرؤه. المحاكاةُ تُعيدُ `null`: هذا ما يراهُ
| الزائرُ فعلاً، وهو السببُ في أنّ السعرَ لا يظهرُ له.
*/
vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: null }),
}));

vi.mock("@/lib/public-api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/public-api")>();

  return {
    ...actual,
    publicApi: {
      course: vi.fn(),
      teacher: (key: string) => teacher(key),
    },
  };
});

const course: CourseDetail = {
  uuid: "a270bec9-54c1-4dff-bb96-84fbcc1e60c2",
  // Equal to the route param below, so the canonical 308 never fires and this
  // file needs no `next/navigation` mock at all.
  slug: "asasyat-altfadl",
  title: "أساسيّات التفاضل",
  description: null,
  cover_url: null,
  subject: null,
  grade_level: null,
  teacher: {
    uuid: "a270bec8-49b4-4aed-bf97-cdb46ae14890",
    slug: "demo-teacher",
    name: "Demo Teacher",
    photo_url: null,
    trust_score: null,
    trust_score_band: "building",
  },
  type: "group",
  lessons_count: 4,
  duration_seconds: 7200,
  price_minor: null,
  currency: null,
  average_rating: null,
  enrolled_count: 0,
  // Empty on purpose: no client child mounts, so only the three states render.
  curriculum: [],
  cohorts: [],
  private_session_minutes: null,
  private_subscription_available: false,
  promo_video_id: null,
};

async function renderPage() {
  const { default: CoursePage } = await import("./page");

  render(
    await CoursePage({ params: Promise.resolve({ slug: "asasyat-altfadl" }) }),
  );
}

describe("the course page's private-session section", () => {
  beforeEach(async () => {
    vi.resetModules();
    teacher.mockReset();

    const { publicApi } = await import("@/lib/public-api");

    vi.mocked(publicApi.course).mockResolvedValue({ data: course });
  });

  it("says the teacher declared no hours when the read came back empty", async () => {
    teacher.mockResolvedValue({ data: { availability: [] } });

    await renderPage();

    expect(screen.getByText("لا مواعيد للحصص الخاصة")).toBeDefined();
    expect(screen.queryByText("تعذّر تحميل مواعيد المدرّس")).toBeNull();
  });

  it("says the READ failed when it failed, never that the teacher is unavailable", async () => {
    teacher.mockRejectedValue(new Error("Marketplace API 500 for /teachers/x"));

    await renderPage();

    expect(screen.getByText("تعذّر تحميل مواعيد المدرّس")).toBeDefined();
    expect(screen.queryByText("لا مواعيد للحصص الخاصة")).toBeNull();
  });

  it("treats a 404 as an answer, because no retry can change it", async () => {
    // The teacher is not publicly listed. Offering «إعادة المحاولة» over that
    // promises something impossible.
    teacher.mockRejectedValue(new NotFoundError("Not found"));

    await renderPage();

    expect(screen.getByText("لا مواعيد للحصص الخاصة")).toBeDefined();
    expect(screen.queryByText("تعذّر تحميل مواعيد المدرّس")).toBeNull();
  });
});
