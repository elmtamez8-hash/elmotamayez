import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
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
const post = vi.fn();

/*
 | ⚠️ A PARTIAL MOCK. `userMessage()` in `lib/errors` reads `ApiError` from this
 | module to decide what the reader is told, so a mock that exports `api` alone
 | makes the failure path throw inside the error handler — which reads as a
 | passing test with an unhandled error beside it.
*/
vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path), post: (path: string) => post(path) },
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
      enrollment_uuid: "e-1",
      title: "الرياضيات — التاسع",
      cover_url: "http://localhost/storage/courses/cover.jpg",
      teacher_name: "أ. سامي",
      is_sequential: true,
      course_type: "group",
      progress_pct: 40,
      completed_count: 4,
      countable_count: 10,
      resume_lesson_uuid: "l-open",
    locked_session_count: 0,
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

/**
 * The page fires SIX reads, and the tabs are built from what comes back — so a
 * test about the strip has to answer each path with its own body.
 *
 * ⚠️ THE EXISTING CASES DELIBERATELY DO NOT USE THIS. `get.mockResolvedValue()`
 * answers every path with the curriculum, whose `data` key is absent — so each
 * side read resolves to an empty list and the tabs it would add are correctly
 * not drawn. That is the shape those tests want, and it is also the proof that
 * a side read returning nothing cannot blank the lessons.
 */
function mockRoutes(bodies: {
  curriculum?: Curriculum;
  exams?: unknown[];
  assignments?: unknown[];
  announcements?: unknown[];
  certificates?: unknown[];
  sessions?: unknown[];
  nextSession?: { data: unknown; seconds_until_start?: number };
}) {
  get.mockImplementation((path: string) => {
    if (path.includes("/curriculum")) return Promise.resolve(bodies.curriculum ?? payload());
    if (path.startsWith("/exams")) return Promise.resolve({ data: bodies.exams ?? [] });
    if (path.startsWith("/assignments")) return Promise.resolve({ data: bodies.assignments ?? [] });
    if (path.includes("/announcements")) {
      return Promise.resolve({ data: bodies.announcements ?? [] });
    }
    if (path.startsWith("/certificates")) {
      return Promise.resolve({ data: bodies.certificates ?? [] });
    }
    if (path.includes("/next-session")) {
      return Promise.resolve(bodies.nextSession ?? { data: null });
    }
    if (path.includes("/sessions")) return Promise.resolve({ data: bodies.sessions ?? [] });
    // The group read is a side read like the others: it may fail without taking
    // the page down, and a course with no groups answers an empty list.
    if (path.includes("/cohorts")) {
      return Promise.resolve({ membership: null, pending_request: null, cohorts: [] });
    }

    return Promise.reject(new Error(`unstubbed: ${path}`));
  });
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

  /*
  | «ابدأِ الكورس من جديد» (طلبُ المالكِ ٢٠٢٦-٠٩-١٤).
  |
  | ⚠️ **والمعرّفُ هو `enrollment_uuid` لا معرّفُ الكورسِ في المسار.** البابُ
  | `/enrollments/{enrollment}/reset`، فخلطُ الاثنَينِ يُنتِجُ ٤٠٤ على زرٍّ
  | يبدو سليماً — وهذه الحالةُ تُثبّتُ المفتاحَ الذي أُضيفَ للحمولةِ من أجلِه.
  */
  it("resets the whole course against the ENROLMENT uuid, not the course one", async () => {
    post.mockResolvedValue({ reset_count: 4, progress_pct: 0, course_completed: false });
    get.mockResolvedValue(payload());

    await renderPage();

    fireEvent.click(await screen.findByRole("button", { name: /ابدأِ الكورس من جديد/ }));

    // ثلاثُ نتائجَ تُقالُ قبلَ الضغط، والشهادةُ أوّلُ ما يُخافُ عليه.
    expect(screen.getByText(/يُقفل كل درس بعد الأول/)).toBeTruthy();
    expect(screen.getByText(/شهادتك/)).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "ابدأْ من جديد" }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith("/enrollments/e-1/reset");
    });
  });

  it("does not offer it on a course nothing has been finished in", async () => {
    // زرٌّ يُعيدُ صفراً إلى صفرٍ سؤالٌ بلا جواب.
    get.mockResolvedValue(payload({
      course: { ...payload().course, completed_count: 0, progress_pct: 0 },
    }));

    await renderPage();

    expect(await screen.findByText("المتباينات")).toBeTruthy();
    expect(screen.queryByRole("button", { name: /ابدأِ الكورس من جديد/ })).toBeNull();
  });

  /*
  | ٠٣٤ · FR-015 — **المنهجُ مفتوحٌ لمن لا مجموعةَ له، والجملةُ فوقَه.**
  |
  | ⛔ كانَ هنا حاجزٌ: `mustChoose` يرسمُ المُنتقيَ وحدَه ويُخفي الشجرةَ كلَّها.
  | وقِيسَ على الإنتاج أنّ إنشاءَ أوّلِ مجموعةٍ لكورسٍ قائمٍ كانَ يُغلِقُ منهجَ
  | كلِّ من سجّلَ قبلَها في اللحظةِ نفسِها — على محتوًى مدفوع.
  |
  | ⚠️ **والتوكيدُ على ظهورِ الدرسِ لا على غيابِ المُنتقي**: المُنتقي باقٍ عمداً
  | (غيرَ حاجز) حتّى تُبنى شاشةُ الإسناد، فقياسُ غيابِه كانَ سيقيسُ الشيءَ الخطأ.
  */
  it("shows the curriculum to a student in no group, with the sentence above it", async () => {
    mockRoutes({
      curriculum: payload({
        cohort_gate: {
          required: true,
          satisfied: false,
          joinable_exists: true,
          message: "لم تُسنَد إلى مجموعة بعد — إدارة المنصّة هي من تُسنِدك.",
        },
      }),
    });

    await renderPage();

    // الشجرةُ نفسُها — لا شاشةَ حاجزةً فوقَها.
    expect(await screen.findByText("المعادلات الخطّيّة")).toBeTruthy();
    expect(screen.getByText(/إدارة المنصّة هي من تُسنِدك/)).toBeTruthy();

    // والضابط: الأقفالُ الأخرى لم تُفتَحْ معها.
    expect(screen.getByText(/أكمِل «المعادلات الخطّيّة» أوّلاً/)).toBeTruthy();
  });

  it("says nothing about groups on a course that has none", async () => {
    // ⚠️ الضابطُ المقابل: تنبيهٌ يظهرُ لكلِّ كورسٍ ضجيجٌ يُعلِّمُ القارئَ تجاهلَه.
    mockRoutes({ curriculum: payload() });

    await renderPage();

    expect(await screen.findByText("المعادلات الخطّيّة")).toBeTruthy();
    expect(screen.queryByText("مجموعتك في هذه المادّة")).toBeNull();
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

  /*
   | US2 — التبويبات (FR-013 · FR-014 · FR-015 · FR-021).
  */

  /*
   | ⚠️ A `recorded` COURSE HAS NO SESSIONS TAB AND NO HEADER AT ALL (FR-014).
   | Not an empty tab and not «لا حصّة قادمة»: a recorded course can never have a
   | session, so both are answers to a question this course does not raise — and
   | a tab with nothing behind it is a promise the course cannot keep.
  */
  it("draws no sessions tab and no next-session header on a recorded course", async () => {
    const recorded = payload();
    recorded.course.course_type = "recorded";
    mockRoutes({ curriculum: recorded });

    await renderPage();

    expect(await screen.findByRole("tab", { name: "المنهج" })).toBeTruthy();
    expect(screen.queryByRole("tab", { name: "الحصص" })).toBeNull();
    expect(screen.queryByText(/لا حصّة قادمة/)).toBeNull();

    // The control: the sessions endpoints were never asked, so this is «not
    // requested» rather than «requested and empty».
    const asked = get.mock.calls.map((call) => String(call[0]));
    expect(asked.some((path) => path.includes("next-session"))).toBe(false);
    expect(asked).not.toContain("/courses/c-1/sessions");
  });

  /*
   | ⚠️ «لا حصّةَ قادمة» IS A SENTENCE, NOT FOUR ZEROES. A countdown showing
   | 00:00:00 under «يبدأ بعد» reads as a page that failed to load, which is the
   | opposite of the answer FR-015 asks for.
  */
  it("says there is no next session rather than counting down to nothing", async () => {
    mockRoutes({});

    await renderPage();

    expect(await screen.findByText(/لا حصّة قادمة/)).toBeTruthy();
    expect(screen.queryByRole("timer")).toBeNull();
    expect(screen.queryByText("يبدأ بعد")).toBeNull();
  });

  /*
   | ⚠️ THE TAB IN THE ADDRESS BAR IS THE TAB THAT OPENS (FR-021). `useTabParam`
   | reads `location.search` in an EFFECT — not with `useSearchParams`, which
   | opts the page out of prerendering without a boundary — so the URL has to be
   | in place before the render, exactly as it is when a shared link is opened.
  */
  it("opens on the tab named in ?tab=", async () => {
    window.history.replaceState(null, "", "/enrollments/c-1?tab=exams");

    mockRoutes({
      exams: [
        {
          uuid: "e-1",
          course_id: 1,
          title: "اختبار الوحدة الأولى",
          description: "",
          duration_minutes: 30,
          passing_score: 60,
          max_attempts: 3,
          status: "published",
          is_published: true,
          my_attempts: { count: 1, best_score: 82, passed: true, last_uuid: "a-1" },
        },
      ],
    });

    await renderPage();

    const tab = await screen.findByRole("tab", { name: "الاختبارات" });
    expect(tab.getAttribute("aria-selected")).toBe("true");

    // And its panel is the one rendered — the curriculum's is unmounted, not
    // merely hidden behind it.
    expect(screen.getByText("اختبار الوحدة الأولى")).toBeTruthy();
    expect(screen.queryByText("المتباينات")).toBeNull();

    // The reader's own record, beside the paper rather than a screen away.
    expect(screen.getByText("ناجح")).toBeTruthy();
    expect(screen.getByText(/أفضل نتيجة/)).toBeTruthy();
  });

  /*
   | ⚠️ THE CERTIFICATE TAB IS DRAWN EVEN WITH NO CERTIFICATE, ALONE AMONG THEM
   | (FR-020). «ما الذي بقي؟» is exactly the question a student without one is
   | asking, so hiding the tab because the answer is «none yet» removes the
   | answer they came for.
  */
  it("keeps the certificate tab and states the condition when none has been issued", async () => {
    window.history.replaceState(null, "", "/enrollments/c-1?tab=certificate");
    mockRoutes({});

    await renderPage();

    expect(await screen.findByRole("tab", { name: "الشهادة" })).toBeTruthy();
    expect(screen.getByText(/تصدر الشهادة تلقائياً/)).toBeTruthy();
    // The number comes from the same counts the progress bar is drawn from —
    // ten countable, four done.
    expect(screen.getByText(/بقي لك/)).toBeTruthy();
  });

  /*
   | ⚠️ THE SESSIONS TAB MUST NOT READ `/class-sessions` — THAT ROUTE ANSWERS A
   | REAL STUDENT `403`.
   |
   | `ClassSessionPolicy::viewAny()` asks for `SESSIONS_VIEW`, and a student
   | holds no spatie team id: they are a member of no workspace, so the context
   | is null and every permission check below it is false. Built on it, this tab
   | swallowed the refusal into an empty list and told a student with a lesson
   | every week «لا حصص في هذه المادّة بعد» — the shape of the five dead
   | endpoints this product already shipped once. A mocked test cannot see the
   | 403 itself, so what it guards is the ROUTE: the student's course sessions
   | come from the enrolment-guarded one, which also bounds both ends on the
   | server («the oldest fifty»).
  */
  it("reads the enrolment-guarded course route, never the teacher's calendar", async () => {
    mockRoutes({});

    await renderPage();

    await screen.findByRole("tab", { name: "الحصص" });

    const asked = get.mock.calls.map((call) => String(call[0]));

    expect(asked).toContain("/courses/c-1/sessions");
    expect(asked.some((path) => path.startsWith("/class-sessions"))).toBe(false);
  });

  /*
   | ⚠️ A SIDE READ THAT FAILS MUST NOT BLANK THE LESSONS. The curriculum is the
   | page; a certificates list that 500s is a tab that cannot be filled, and the
   | truthful thing to show there is its own empty state.
  */
  it("still renders the curriculum when a tab's own read fails", async () => {
    get.mockImplementation((path: string) =>
      path.includes("/curriculum")
        ? Promise.resolve(payload())
        : Promise.reject(new Error("boom")),
    );

    await renderPage();

    expect(await screen.findByText("المتباينات")).toBeTruthy();
    expect(screen.queryByRole("alert")).toBeNull();
  });
});
