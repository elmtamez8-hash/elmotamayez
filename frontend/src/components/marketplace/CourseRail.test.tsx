import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CourseOwnershipProvider } from "./CourseOwnership";
import { CourseRail } from "./CourseRail";
import { ApiError } from "@/lib/api";
import type { Curriculum } from "@/lib/curriculum";
import type { User } from "@/lib/types";

/*
| صفحةُ الكورسِ تعترفُ بصاحبِها — أو لا تعِدُه بشيء.
|
| ⛔ العطبُ الذي كُتِبَ هذا الملفُّ لأجلِه، مقيسٌ على الإنتاجِ في ٢٠٢٦-٠٩-١٣:
| صفحةُ `/courses/{slug}` كانت تحملُ **رابطاً واحداً** — إلى المدرّس. فطالبٌ دفعَ
| ثمنَ الكورسِ ووقفَ على صفحتِه لا يجدُ جملةً تقولُ إنّه له، ولا باباً يدخلُ منه؛
| يرى ما يراهُ الغريبُ بالحرف. الخادمُ كانَ سليماً والشاشةُ الجوّانيّةُ سليمة،
| والمفقودُ هو الطريقُ بينهما.
|
| ⚠️ وكلُّ حالةٍ هنا تُقاسُ في الاتّجاهَين. ملفٌّ يؤكّدُ ظهورَ لوحةِ المالكِ وحدَه
| يمرُّ على تنفيذٍ يعرضُها للجميع — وهو تسريبُ «أنت مسجّل» لكلِّ زائر.
*/

let mockUser: Partial<User> | null = null;
let mockLoading = false;
const get = vi.fn();

vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: mockUser, loading: mockLoading }),
}));

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path) },
}));

function curriculum(overrides: Partial<Curriculum["course"]> = {}): Curriculum {
  return {
    course: {
      uuid: "c-1",
      enrollment_uuid: "e-1",
      title: "Authoring Showcase",
      cover_url: null,
      teacher_name: "Sami Teacher",
      is_sequential: true,
      course_type: "recorded",
      has_sessions: false,
      progress_pct: 25,
      completed_count: 2,
      countable_count: 8,
      resume_lesson_uuid: "l-3",
      locked_session_count: 0,
      ...overrides,
    },
    cohort_gate: { required: false, satisfied: true, joinable_exists: false, message: null },
    sections: [],
  };
}

async function renderRail(isFull = false) {
  await act(async () => {
    render(
      <CourseOwnershipProvider courseUuid="c-1">
        <CourseRail
          priceMinor={49900}
          currency="QAR"
          courseUuid="c-1"
          isFull={isFull}
          teacher={{
            uuid: "t-1",
            slug: "sami-teacher",
            name: "Sami Teacher",
            photo_url: null,
            trust_score: null,
            trust_score_band: "building",
          }}
        />
      </CourseOwnershipProvider>,
    );
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = null;
  mockLoading = false;
  get.mockRejectedValue(new ApiError("لا تملك تسجيلاً في هذا الكورس.", 403, {}));
});

describe("CourseRail", () => {
  it("offers the way in to somebody who owns the course", async () => {
    mockUser = { uuid: "u-1", platform_role: "student" };
    get.mockResolvedValue(curriculum());

    await renderRail();

    expect(screen.getByText("أنت مسجّل في هذا الكورس")).toBeTruthy();

    // ⚠️ النسبةُ مقروءةٌ لمن لا يرى الشريط، لا لونٌ وحدَه.
    const bar = screen.getByRole("progressbar");
    expect(bar.getAttribute("aria-valuenow")).toBe("25");

    const hrefs = screen.getAllByRole("link").map((a) => a.getAttribute("href"));
    expect(hrefs).toContain("/learn/l-3");
    expect(hrefs).toContain("/enrollments/c-1");

    // ولا يُدعى إلى شراءِ ما يملكُه.
    expect(screen.queryByText("سجّل في الكورس")).toBeNull();
  });

  it("says «ابدأ الكورس» before the first item and «تابع» after it", async () => {
    mockUser = { uuid: "u-1", platform_role: "student" };
    get.mockResolvedValue(curriculum({ progress_pct: 0, completed_count: 0 }));

    await renderRail();

    expect(screen.getByText("ابدأ الكورس")).toBeTruthy();
    expect(screen.getByText(/عناصر بانتظارك|عنصراً بانتظارك/)).toBeTruthy();
  });

  it("shows no resume button when the server names no item to resume", async () => {
    mockUser = { uuid: "u-1", platform_role: "student" };
    get.mockResolvedValue(curriculum({ resume_lesson_uuid: null }));

    await renderRail();

    /*
      ⚠️ غائبٌ لا معطَّل. `resume_lesson_uuid` فارغٌ على كورسٍ انتهى وعلى كورسٍ
      أوّلُ عنصرٍ فيه مقفول — وزرٌّ باهتٌ هناك يَعِدُ ثمّ يرفض.
    */
    const hrefs = screen.getAllByRole("link").map((a) => a.getAttribute("href"));
    expect(hrefs.some((href) => href?.startsWith("/learn/"))).toBe(false);

    // والبابُ الثاني يبقى، فلا يُترَكُ المالكُ بلا مدخلٍ إطلاقاً.
    expect(hrefs).toContain("/enrollments/c-1");
  });

  it("shows the buying face to a learner who has not bought it", async () => {
    mockUser = { uuid: "u-2", platform_role: "student" };
    // The endpoint's own answer to this question, verbatim.
    get.mockRejectedValue(new ApiError("لا تملك تسجيلاً في هذا الكورس.", 403, {}));

    await renderRail();

    expect(screen.getByText("سجّل في الكورس")).toBeTruthy();
    expect(screen.queryByText("أنت مسجّل في هذا الكورس")).toBeNull();

    // ⚠️ ولا جملةَ خطأٍ: لم يضغطْ أحدٌ شيئاً، والصفحةُ العامّةُ هي الجوابُ الصحيح.
    expect(screen.queryByText(/تعذّر|خطأ/)).toBeNull();
  });

  it("never asks on behalf of a teacher, and never calls them enrolled", async () => {
    mockUser = { uuid: "t-1", platform_role: "teacher" };

    await renderRail();

    expect(get).not.toHaveBeenCalled();
    expect(screen.queryByText("أنت مسجّل في هذا الكورس")).toBeNull();
  });

  it("asks nothing while the session is still being restored", async () => {
    // ⚠️ `loading` ليس «خارجَ الحساب». `useAuth` يبدأ كلَّ صفحةٍ بـ`user === null`
    // ريثما يستعيدُ الجلسة، فالتصرّفُ على تلك القيمةِ يعاملُ صاحبَ الكورسِ كغريب.
    mockLoading = true;
    mockUser = null;

    await renderRail();

    expect(get).not.toHaveBeenCalled();
  });

  /*
  | ⛔ **الشاشةُ لا تَعِدُ بما يرفضُه الباب.**
  |
  | `CreateOrder` يرفضُ كورساً اكتملت مجموعاتُه (٠٣٤ · FR-023)، والعمودُ كانَ
  | يعرضُ «سجّل في الكورس» بالسعرِ فوقَه بلا حرفٍ يقولُ ذلك — قِيسَ بالمشي على
  | الإنتاجِ ٢٠٢٦-٠٩-١٤.
  |
  | ⚠️ **والتوكيدُ على غيابِ الزرّ، لا على وجودِ الجملة.** جملةٌ تُضافُ فوقَ زرٍّ
  | باقٍ تُرضي توكيداً يقرأُ النصَّ وتترُكُ الوعدَ الكاذبَ في مكانِه — والقاعدةُ
  | المكتوبةُ في هذا الملفِّ نفسِه: يغيبُ، ولا يُعطَّل.
  */
  it("withholds the way in when the course is full, and says why", async () => {
    await renderRail(true);

    expect(screen.queryByRole("link", { name: "سجّل في الكورس" })).toBeNull();
    expect(screen.getByText("اكتملت مجموعات هذا الكورس")).toBeTruthy();
    expect(screen.getByText(/تبويب «المجموعات المتاحة»/)).toBeTruthy();
  });

  it("keeps the way in on a course that still has a place", async () => {
    // ⚠️ الحارسُ في الاتّجاهِ الآخر: شرطٌ مقلوبٌ يُخفي الزرَّ عن كلِّ كورس.
    await renderRail(false);

    expect(screen.getByRole("link", { name: "سجّل في الكورس" })).toBeTruthy();
    expect(screen.queryByText("اكتملت مجموعات هذا الكورس")).toBeNull();
  });
});
