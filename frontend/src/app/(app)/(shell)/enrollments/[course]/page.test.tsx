import { act, render, screen, waitFor } from "@testing-library/react";
import { Suspense } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CourseCurriculumPage from "./page";
import type { Curriculum, CurriculumLesson } from "@/lib/curriculum";

/*
| صفحةُ المادّة — المنهجُ يقولُ ما هو مفتوحٌ قبلَ الضغط (US1 · SC-002 · SC-003).
|
| ⚠️ لا يصلُ إلى أيٍّ من هذا اختبارُ خلفيّة. الخادمُ يُرسِلُ `state` و`lock`
| صحيحين ولا يعرفُ شيئاً عمّا يُرسَمُ منهما: صفٌّ مقفولٌ يُعرَضُ رابطاً هو
| استجابةُ ٢٠٠ سليمةٌ تماماً من جهةِ الـAPI. وPlaywright يبني للإنتاجِ ويحتاجُ
| خادمين، فلا أحدَ يُشغِّلُه داخلَ حلقةِ التطوير.
*/

const get = vi.fn();

/*
 | ⚠️ A PARTIAL MOCK. `userMessage()` in `lib/errors` reads `ApiError` from this
 | module to decide what the reader is told, so a mock that exports `api` alone
 | makes the failure path throw inside the error handler — which reads as a
 | passing test with an unhandled error beside it.
*/
vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path) },
}));

function lesson(overrides: Partial<CurriculumLesson> = {}): CurriculumLesson {
  return {
    uuid: "l-open",
    title: "المعادلات الخطّيّة",
    type: "video",
    type_label: "فيديو",
    family: "uploaded",
    asset_kind: "video",
    is_completable: true,
    duration_seconds: 480,
    state: "open",
    lock: null,
    ...overrides,
  };
}

function payload(overrides: Partial<Curriculum> = {}): Curriculum {
  return {
    course: {
      uuid: "c-1",
      title: "الرياضيات — التاسع",
      cover_url: "http://localhost/storage/courses/cover.jpg",
      teacher_name: "أ. سامي",
      is_sequential: true,
      course_type: "group",
      progress_pct: 40,
      completed_count: 4,
      countable_count: 10,
      resume_lesson_uuid: "l-open",
    },
    cohort_gate: { required: false, satisfied: true, joinable_exists: false, message: null },
    sections: [
      {
        uuid: "s-1",
        title: "الجبر",
        order: 1,
        chapters: [
          {
            uuid: "ch-1",
            title: "المعادلات",
            order: 1,
            lessons: [
              lesson(),
              lesson({
                uuid: "l-locked",
                title: "المتباينات",
                state: "locked",
                lock: {
                  code: "sequence",
                  message: "أكمِل «المعادلات الخطّيّة» أوّلاً — هذا الكورس متسلسل.",
                  blocked_by_title: "المعادلات الخطّيّة",
                },
              }),
            ],
          },
        ],
      },
    ],
    ...overrides,
  };
}

/*
 | ⚠️ THE `<Suspense>` IS NOT CEREMONY. Next passes `params` as a PROMISE and the
 | page reads it with `use()`, which suspends on the first render — without a
 | boundary the tree throws to the nearest one, there is none, and every
 | assertion below fails against an empty `<body>` for a reason that has nothing
 | to do with the page. In the app the route's own boundary supplies it.
*/
async function renderPage() {
  // ⚠️ `await act(...)`, not a bare `render`. `use()` SUSPENDS on the first
  // render, and a synchronous render inside an act scope never flushes the
  // resumption — every assertion then runs against an empty `<body>` and reports
  // "element not found" about a page that simply had not been allowed to finish.
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <CourseCurriculumPage params={Promise.resolve({ course: "c-1" })} />
      </Suspense>,
    );
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  window.history.replaceState(null, "", "/enrollments/c-1");
});

describe("CourseCurriculumPage", () => {
  it("shows the lock and its reason without the student pressing anything", async () => {
    get.mockResolvedValue(payload());

    await renderPage();

    expect(await screen.findByText("المتباينات")).toBeTruthy();
    expect(screen.getByText(/أكمِل «المعادلات الخطّيّة» أوّلاً/)).toBeTruthy();

    // The open row links; the locked one is not among the links at all.
    const hrefs = screen.getAllByRole("link").map((a) => a.getAttribute("href"));
    expect(hrefs).toContain("/learn/l-open");
    expect(hrefs).not.toContain("/learn/l-locked");
  });

  it("offers «تابعْ من هنا» pointing at the item the server named", async () => {
    get.mockResolvedValue(payload());

    await renderPage();

    const resume = await screen.findByRole("link", { name: "تابعْ من هنا" });
    expect(resume.getAttribute("href")).toBe("/learn/l-open");
  });

  /*
   | ⚠️ نهايتا الطيف، وكلتاهما حقيقيّتان: كورسٌ انتهى، وكورسٌ أوّلُ عنصرٍ فيه
   | مقفول. زرٌّ يُجيبُ ٤٠٣ أسوأُ من غيابِ الزرّ.
  */
  it("hides «تابعْ من هنا» when the server says there is nothing to resume", async () => {
    const done = payload();
    done.course.resume_lesson_uuid = null;
    get.mockResolvedValue(done);

    await renderPage();

    expect(await screen.findByText("المتباينات")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "تابعْ من هنا" })).toBeNull();
  });

  it("renders a course with no cover instead of breaking on it", async () => {
    const bare = payload();
    bare.course.cover_url = null;
    get.mockResolvedValue(bare);

    await renderPage();

    expect(await screen.findByRole("heading", { name: "الرياضيات — التاسع" })).toBeTruthy();
    expect(screen.queryByRole("img")).toBeNull();
  });

  it("locks nothing in a course that is not sequential", async () => {
    const free = payload();
    free.course.is_sequential = false;
    free.sections[0].chapters[0].lessons = [
      lesson(),
      lesson({ uuid: "l-2", title: "المتباينات" }),
    ];
    get.mockResolvedValue(free);

    await renderPage();

    expect(await screen.findByText("المتباينات")).toBeTruthy();
    expect(screen.queryByText("مقفول")).toBeNull();
    expect(screen.getAllByText("متاح")).toHaveLength(2);
  });

  /*
   | ⚠️ `.catch(() => undefined)` يرسمُ صفحةً بيضاءَ دائمةً والسببُ في الاستجابةِ
   | لم يقرأْه أحد. قاعدةُ «لا تُظهِرْ خطأً خامّاً» ليست قاعدةَ «لا تُظهِرْ شيئاً».
  */
  it("says why when the fetch fails, instead of rendering nothing", async () => {
    get.mockRejectedValue(new Error("network"));

    await renderPage();

    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
    expect(screen.getByRole("button", { name: "إعادة المحاولة" })).toBeTruthy();
  });
});
