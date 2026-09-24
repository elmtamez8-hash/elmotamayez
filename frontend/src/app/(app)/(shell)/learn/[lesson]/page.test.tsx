import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Suspense } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import LearnLessonPage from "./page";

/*
| ⚠️ «مافيش حاجة بتقول اني اكملت الدرس ولا زرار» — بلاغُ ٢٠٢٦-٠٩-٠٦، والنسبةُ
| واقفةٌ على «أتممتَ ٠ من ٣ — ٠٪».
|
| المسارُ قائمٌ منذُ ٠١٦، و`enrollment_uuid` في الحمولةِ يحملُ تعليقاً يقولُ إنّه
| «ما تستعملُه شاشةُ الطالبِ لتعليمِ العنصرِ مكتملاً» — ولا ملفَّ في الواجهةِ
| ينادِيه. فلا فيديو ولا مقالٌ يكتملُ أبداً، ولا شهادةَ تصدرُ لأحد.
|
| ⚠️ والحالةُ الثالثةُ هنا هي التي تحرسُ الأخطر: الاختبارُ يُحتسَبُ في المقامِ
| ويكتملُ **بالتسليم**، فزرٌّ عليه تخطٍّ للاختبارِ يحرّكُ النسبة. الخادمُ يرفضُ
| كذلك (`LessonSelfCompletionTest`) — وإخفاءُ الضابطِ ليس حرساً، وهذان بابانِ لا
| واحد.
*/

const get = vi.fn();
const post = vi.fn();
const upload = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  // ⚠️ `importOriginal` وليسَ كائناً عارياً: الصفحةُ تستوردُ `ApiError`
  // كذلك، وتمويهٌ يسقطُهُ يجعلُ `err instanceof ApiError` يرمي على
  // `undefined` في منتصفِ الرسم — فيخرجُ جسمٌ فارغٌ تماماً وتقولُ
  // الرسالةُ «لم أجدِ الزرّ» لا «لم يُرسمْ شيء».
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: (path: string) => get(path),
    post: (path: string) => post(path),
    upload: (path: string, body: FormData) => upload(path, body),
  },
}));

const requestPlayback = vi.fn();

vi.mock("@/lib/media", () => ({
  media: { grant: vi.fn(), requestPlayback: (uuid: string) => requestPlayback(uuid) },
}));

// نقاشُ الدرسِ يفتحُ مقبساً حيّاً، ولا شأنَ له بهذا القياس.
vi.mock("@/components/community/SessionChat", () => ({ SessionChat: () => null }));

const ARTICLE = {
  uuid: "l-1",
  title: "الدرس الأول",
  type: "article",
  type_label: "مقال",
  is_completable: true,
  may_self_complete: true,
  course_uuid: "c-1",
  is_completed: false,
  content: "نص",
  content_html: "<p>نص</p>",
  external_url: null,
  has_asset: false,
  attachments: [],
  reference: null,
  exam_gate: null,
  exam_gate_label: null,
};

/** درسٌ في شجرةِ المنهج. الحالةُ والقفلُ جوابُ الخادمِ لا حسابُ الشاشة. */
function node(uuid: string, title: string, over: Record<string, unknown> = {}) {
  return {
    uuid,
    title,
    type: "article",
    type_label: "مقال",
    family: "inline",
    asset_kind: null,
    is_completable: true,
    duration_seconds: 0,
    state: "open",
    lock: null,
    ...over,
  };
}

/**
 * ⚠️ الدرسُ الأخيرُ **مقفولٌ بالتسلسل**، وهو الحالةُ التي طُلِبَت: كورسٌ متسلسلٌ
 * والدرسُ الحاليُّ غيرُ مكتملٍ ⇒ «التالي» لا يُفتَح. والقفلُ يأتي من الخادمِ
 * (`state` + `lock`) ولا يُشتَقُّ هنا من `is_sequential`.
 */
function tree(lessons?: Record<string, unknown>[]) {
  return {
    course: {
      uuid: "c-1",
      title: "مقدّمة في لارافيل",
      cover_url: null,
      teacher_name: "أكاديميّة ديمو",
      is_sequential: true,
      course_type: "recorded",
      has_sessions: false,
      progress_pct: 33,
      completed_count: 1,
      countable_count: 3,
      enrollment_uuid: "e-1",
      resume_lesson_uuid: "l-1",
      locked_session_count: 0,
    },
    cohort_gate: { required: false, satisfied: true, joinable_exists: false, message: null },
    sections: [
      {
        uuid: "s-1",
        title: "القسم الأول",
        order: 1,
        chapters: [
          {
            uuid: "ch-1",
            title: "الفصل الأول",
            order: 1,
            lessons: lessons ?? [
              node("l-0", "الدرس صفر", { state: "completed" }),
              node("l-1", "الدرس الأول"),
              node("l-2", "الدرس الثاني", {
                state: "locked",
                lock: {
                  code: "sequence",
                  message: "أكمِل «الدرس الأول» أولاً — هذا الكورس متسلسل.",
                  blocked_by_title: "الدرس الأول",
                },
              }),
            ],
          },
        ],
      },
    ],
  };
}

function answer(
  over: Record<string, unknown> = {},
  lesson: Record<string, unknown> = {},
  curriculum: unknown = tree(),
) {
  /*
   | ⚠️ تمويهٌ **حسبَ المسار** لا جوابٌ واحدٌ للجميع. الصفحةُ تطلبُ الآن شجرةَ
   | المنهجِ كذلك، و`mockResolvedValue` يردُّ حمولةَ الدرسِ على المسارَين —
   | فـ`tree.sections` تصيرُ `undefined` وينهارُ الرسمُ كلُّه برسالةٍ لا تُسمّي
   | السبب.
   */
  get.mockImplementation((path: string) =>
    path.includes("/curriculum")
      ? Promise.resolve(curriculum)
      : Promise.resolve({
          lesson: { ...ARTICLE, ...lesson },
          can_access: true,
          blocked_reason: null,
          blocked_message: null,
          blocked_by_title: null,
          enrollment_uuid: "e-1",
          ...over,
        }),
  );
}

async function open() {
  /*
   * ⚠️ `await act(...)` لا `render` عارياً — والقاعدةُ مكتوبةٌ سلفاً في
   * `enrollments/[course]/page.test.tsx` ووقعتُ فيها رغمَ ذلك. Next يمرّرُ
   * `params` **وعداً** و`use()` يُعلِّقُ أوّلَ رسم؛ ورسمٌ متزامنٌ داخلَ نطاقِ `act`
   * لا يُفرِغُ الاستئناف، فتقفُ الشجرةُ على البديلِ إلى الأبد وتسقطُ كلُّ حالةٍ
   * برسالةٍ تقولُ «لم أجدِ الزرّ» عن صفحةٍ لم يُسمَحْ لها أن تُكمِل.
   */
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <LearnLessonPage params={Promise.resolve({ lesson: "l-1" })} />
      </Suspense>,
    );
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  answer();
});

describe("marking a lesson complete", () => {
  it("offers the control, and sends the completion to the enrolment's own route", async () => {
    post.mockResolvedValue({ status: "completed", course_completed: false, progress_pct: 50 });

    await open();

    fireEvent.click(await screen.findByRole("button", { name: "علِّمه مكتملاً" }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith("/enrollments/e-1/lessons/l-1/complete");
    });

    expect(await screen.findByText(/أتممتَ هذا الدرس/)).toBeDefined();
  });

  it("says so from the start when the server reports it already done", async () => {
    answer({}, { is_completed: true });

    await open();

    expect(await screen.findByText(/أتممتَ هذا الدرس/)).toBeDefined();
    expect(screen.queryByRole("button", { name: "علِّمه مكتملاً" })).toBeNull();
  });

  /*
  | التراجعُ عن الإتمام (طلبُ المالكِ ٢٠٢٦-٠٩-١٤).
  |
  | ⚠️ **والقفلُ الراجعُ مقيسٌ في النصِّ لا موصوف.** هو أهمُّ نتيجةٍ في هذه
  | الميزةِ وأكثرُها مفاجأةً للطالب، فاختفاؤه من سؤالِ التأكيدِ عطبٌ حقيقيٌّ لا
  | نقصُ صياغة — وهذه الحالةُ هي ما يسقطُ إن حُذِفَت الجملة.
  */
  it("offers the undo once it is done, and names the re-lock BEFORE the press", async () => {
    answer({}, { is_completed: true });

    await open();

    fireEvent.click(await screen.findByRole("button", { name: /أعِدْه من أوّله/ }));

    expect(screen.getByText(/يُقفل ما بعده في المسار/)).toBeDefined();
    // والشهادةُ أوّلُ ما يُخافُ عليه.
    expect(screen.getByText(/شهادتك/)).toBeDefined();
    expect(post).not.toHaveBeenCalled();
  });

  it("posts the reset only on confirm, and re-reads the tree rather than unlocking locally", async () => {
    post.mockResolvedValue({ reset_count: 1, progress_pct: 0, course_completed: false });
    answer({}, { is_completed: true });

    await open();

    get.mockClear();
    fireEvent.click(await screen.findByRole("button", { name: /أعِدْه من أوّله/ }));
    fireEvent.click(screen.getByRole("button", { name: "أعِدِ الدرس" }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith("/enrollments/e-1/lessons/l-1/reset");
    });

    // ⚠️ القفلُ قرارُ الخادمِ — إعادةُ الجلبِ هي الفرقُ بين شريطٍ صادقٍ وآخرَ
    // يقولُ «مكتمل» فوقَ «٠٪».
    await waitFor(() => {
      expect(get.mock.calls.some(([path]) => String(path).includes("/curriculum"))).toBe(true);
    });

    await waitFor(() => {
      expect(screen.queryByText(/أتممتَ هذا الدرس/)).toBeNull();
    });
    expect(await screen.findByRole("button", { name: "علِّمه مكتملاً" })).toBeDefined();
  });

  it("does not offer an undo for something not done yet", async () => {
    answer();

    await open();

    expect(await screen.findByRole("button", { name: "علِّمه مكتملاً" })).toBeDefined();
    expect(screen.queryByRole("button", { name: /أعِدْه من أوّله/ })).toBeNull();
  });

  it("NEVER offers it on an exam, and says what does complete one", async () => {
    // ⚠️ التوكيدُ الحارس. عنصرُ الاختبارِ يُحتسَبُ (`is_completable`) ويكتملُ
    // بالتسليمِ لا باليد؛ زرٌّ هنا يحرّكُ نسبةَ طالبٍ لم يجبْ سؤالاً.
    answer({}, { type: "exam", type_label: "اختبار", may_self_complete: false, reference: null });

    await open();

    expect(await screen.findByText(/يكتمل هذا العنصر تلقائياً عند تسليم الاختبار/)).toBeDefined();
    expect(screen.queryByRole("button", { name: "علِّمه مكتملاً" })).toBeNull();
  });

  it("offers nothing to the author, who has no enrolment behind the page", async () => {
    answer({ enrollment_uuid: null });

    await open();

    // ⚠️ بالعنوانِ لا بالنصّ: اسمُ الدرسِ صارَ يظهرُ مرّتَين — ترويسةً وصفّاً في
    // الشريطِ الجانبيّ — فبحثٌ نصّيٌّ عامٌّ يسقطُ بـ«وجدتُ أكثرَ من عنصر».
    await screen.findByRole("heading", { name: "الدرس الأول" });

    expect(screen.queryByRole("button", { name: "علِّمه مكتملاً" })).toBeNull();
  });

  it("offers nothing on a locked lesson", async () => {
    answer({ can_access: false, blocked_reason: "sequence", blocked_message: "أكمِل ما قبله." });

    await open();

    await screen.findByText("أكمِل ما قبله.");

    expect(screen.queryByRole("button", { name: "علِّمه مكتملاً" })).toBeNull();
  });

  it("congratulates once the whole course lands, because the certificate hangs off it", async () => {
    post.mockResolvedValue({ status: "completed", course_completed: true, progress_pct: 100 });

    await open();

    fireEvent.click(await screen.findByRole("button", { name: "علِّمه مكتملاً" }));

    expect(await screen.findByText("أكملتَ الكورس كلّه")).toBeDefined();
  });

  it("says why a refusal happened instead of leaving the button silent", async () => {
    post.mockRejectedValue(new Error("nope"));

    await open();

    fireEvent.click(await screen.findByRole("button", { name: "علِّمه مكتملاً" }));

    expect(await screen.findByText("لم يُسجَّل الإتمام")).toBeDefined();
  });
});

/*
| ⛔ الحالةُ التي كانت تفشلُ قبلَ أن تُكتَبَ الشيفرة (٠٣٢ · US1).
|
| الملفُّ يحملُ ستّةَ فروعٍ للأنواعِ ولم يكنْ فيها `embed`، فالطالبُ الذي دفعَ
| يرى العنوانَ وزرَّ الإتمامِ **فوقَ بطاقةٍ فارغة** بلا فيديو ولا خطأٍ ولا حالةِ
| فراغ — والزائرُ المجّانيُّ يرى الفيديو. و`SC-005` تمرُّ خضراءَ فوقَها لأنّها
| تقيسُ النسبةَ والشهادةَ لا ما رآهُ أحد.
*/
describe("an embedded lesson on the enrolled student's own screen", () => {
  const EMBED = {
    type: "embed",
    type_label: "فيديو مُضمَّن",
    content: null,
    content_html: "",
    external_url: "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
  };

  it("draws the frame, not an empty card", async () => {
    answer({}, EMBED);

    await open();

    const frame = await screen.findByTitle("الدرس الأول");

    expect(frame.tagName).toBe("IFRAME");
    expect(frame.getAttribute("src")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    );
  });

  it("keeps the frame out of the top-level navigation and off the wide permission set", async () => {
    answer({}, EMBED);

    await open();

    const frame = await screen.findByTitle("الدرس الأول");

    // ⚠️ الغيابُ هو الحارس: `allow-top-navigation` يجعلُ إطاراً على صفحةٍ عامّةٍ
    // لنا قادراً على تحويلِ اللسانِ كلِّه إلى واجهةِ دفعٍ مقلَّدة.
    expect(frame.getAttribute("sandbox")).toBe(
      "allow-scripts allow-same-origin allow-presentation",
    );
    expect(frame.getAttribute("referrerpolicy")).toBe("strict-origin");
    expect(frame.getAttribute("allow")).not.toContain("clipboard-write");
    expect(frame.getAttribute("allow")).not.toContain("gyroscope");
  });

  it("still offers the completion control — the item is in the denominator", async () => {
    answer({}, EMBED);

    await open();

    expect(await screen.findByRole("button", { name: "علِّمه مكتملاً" })).toBeDefined();
  });

  it("says the video lives elsewhere, always and not on a detection we do not have", async () => {
    answer({}, EMBED);

    await open();

    expect(await screen.findByText(/مستضافة خارج المنصّة/)).toBeDefined();
  });
});

/*
| الانتقالُ بينَ الدروس — طلبُ ٢٠٢٦-٠٩-١٣.
|
| ⚠️ **والقياسُ على جوابِ الخادمِ لا على `is_sequential`.** الشاشةُ لا تحسبُ
| «متسلسلٌ وغيرُ مكتملٍ ⇒ اقفل»: `Enrollment::accessTo()` يقرِّرُ ذلك ومعه بوّابةُ
| الاختبارِ ومقعدُ الحصّةِ والمجموعة. فكلُّ حالةٍ هنا تُبدِّلُ `state`/`lock` في
| الحمولةِ وتقيسُ ما رُسِم — واختبارٌ يُبدِّلُ `is_sequential` كانَ سيقيسُ القاعدةَ
| الخطأ.
*/
describe("moving between lessons", () => {
  it("links to the next lesson when the server says it is open", async () => {
    // شجرةٌ التاليةُ فيها مفتوحة — والافتراضيّةُ تقفلُها عمداً، فهي حالةُ
    // «المتسلسل» التي تليها.
    answer({}, {}, tree([node("l-1", "الدرس الأول"), node("l-2", "الدرس الثاني")]));
    await open();

    const link = await screen.findByRole("link", { name: /الدرس التالي/ });

    expect(link.getAttribute("href")).toBe("/learn/l-2");
  });

  it("refuses the next lesson as text, never a link, and says why", async () => {
    // الحالةُ المطلوبةُ حرفيّاً: كورسٌ متسلسلٌ والدرسُ الحاليُّ غيرُ مكتمل.
    answer();
    await open();

    /*
     | ⚠️ صفُّ «مقفول» ليس رابطاً ولا زرّاً معطَّلاً (٠١٦ · FR-007): رابطٌ يبدو
     | معطَّلاً يبقى قابلاً للنقرِ بلوحةِ المفاتيح، وزرٌّ `disabled` يخرجُ من
     | ترتيبِ التنقّلِ فلا يعرفُ أحدٌ لماذا توقّف.
     */
    expect(screen.queryByRole("link", { name: /الدرس الثاني/ })).toBeNull();

    // والسببُ من الخادمِ ويُسمّي العنصرَ المطلوب — وهذا كلُّ معنى FR-043.
    expect(
      screen.getAllByText("أكمِل «الدرس الأول» أولاً — هذا الكورس متسلسل.").length,
    ).toBeGreaterThan(0);
  });

  it("says so at the edges instead of drawing a dead tile", async () => {
    answer({}, {}, tree([node("l-1", "الدرس الأول")]));
    await open();

    expect(await screen.findByText("هذا أول درس في الكورس.")).toBeTruthy();
    expect(screen.getByText("هذا آخر درس في الكورس.")).toBeTruthy();
  });

  it("re-reads the curriculum after completing, so the lock actually lifts", async () => {
    /*
     | ⚠️ **إعادةُ جلبٍ لا قلبٌ محلّيّ.** فتحُ «التالي» تفاؤلاً بعدَ الإتمامِ
     | يفترضُ أنّ التسلسلَ هو الشرطُ الوحيد؛ وقد تقفُ خلفَه بوّابةُ اختبارٍ فيظهرُ
     | الزرُّ مفتوحاً ويردُّ البابُ ‏٤٠٣.
     */
    answer();
    await open();

    expect(screen.queryByRole("link", { name: /الدرس التالي/ })).toBeNull();

    post.mockResolvedValue({ status: "completed", course_completed: false, progress_pct: 66 });
    // الشجرةُ التالية: الخادمُ فتحَ ما بعدَه.
    answer({}, { is_completed: true }, tree([
      node("l-1", "الدرس الأول", { state: "completed" }),
      node("l-2", "الدرس الثاني"),
    ]));

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "علِّمه مكتملاً" }));
    });

    await waitFor(() =>
      expect(screen.getByRole("link", { name: /الدرس التالي/ }).getAttribute("href")).toBe(
        "/learn/l-2",
      ),
    );
  });

  it("marks where the reader is with aria-current, not colour alone", async () => {
    answer();
    await open();

    const current = await screen.findByRole("link", { current: "page" });

    expect(current.getAttribute("href")).toBe("/learn/l-1");
  });
});

/*
| A video item with no file attached used to ask for a grant anyway, and the
| server's «لا يوجد ملف» 403 reached the student as «تعذّرت المشاهدة — لا تملك
| صلاحية لهذا الإجراء» about a lesson they own. `has_asset` is the server's
| answer, so the screen asks for nothing and says the file is not up yet.
*/
describe("a media lesson with no file yet", () => {
  it("says there is no video yet, and never asks for a grant", async () => {
    answer({}, { type: "video", type_label: "فيديو", has_asset: false });

    await open();

    expect(await screen.findByText("لا يوجد فيديو لهذا الدرس بعد")).toBeTruthy();
    expect(screen.queryByText("تعذّرت المشاهدة")).toBeNull();
    expect(requestPlayback).not.toHaveBeenCalled();
  });

  it("still asks for the grant when the file is there", async () => {
    requestPlayback.mockReturnValue(new Promise(() => {}));
    answer({}, { type: "video", type_label: "فيديو", has_asset: true });

    await open();

    await waitFor(() => expect(requestPlayback).toHaveBeenCalledWith("l-1"));
    expect(screen.queryByText("لا يوجد فيديو لهذا الدرس بعد")).toBeNull();
  });
});

/*
| An assignment item: the homework and its hand-in sit where the item sits, and
| the item completes from the HAND-IN — never from a button. The server refuses
| a self-declare on this type (`AssignmentLessonTest`); this measures the half
| only a component test can see, that the screen offers none.
*/
describe("an assignment item", () => {
  const HOMEWORK_REFERENCE = {
    uuid: "hw-1",
    title: "واجب الكسور",
    due_at: "2026-10-01T12:00:00+03:00",
    points: 20,
    submission_type: "text",
    late_policy: "accept",
  };

  const HOMEWORK = {
    uuid: "hw-1",
    title: "واجب الكسور",
    course: null,
    teacher: null,
    description: "حلّ التمارين ١–٥.",
    points: 20,
    due_at: "2026-10-01T12:00:00+03:00",
    submission_type: "text",
    late_policy: "accept",
    late_penalty_pct_per_day: 0,
    late_penalty_cap_pct: 100,
    status: "published",
    published_at: null,
    submitted_count: null,
    pending_count: null,
    my_submission: null,
  };

  function answerAssignment(isCompleted = false, enrollmentUuid: string | null = "e-1") {
    const lessonPayload = {
      lesson: {
        ...ARTICLE,
        type: "assignment",
        type_label: "واجب",
        may_self_complete: false,
        is_completed: isCompleted,
        content: null,
        content_html: "",
        reference: HOMEWORK_REFERENCE,
      },
      can_access: true,
      blocked_reason: null,
      blocked_message: null,
      blocked_by_title: null,
      enrollment_uuid: enrollmentUuid,
    };

    get.mockImplementation((path: string) => {
      if (path.includes("/curriculum")) return Promise.resolve(tree());
      if (path.startsWith("/assignments/")) return Promise.resolve({ data: HOMEWORK });

      return Promise.resolve(lessonPayload);
    });
  }

  it("shows the homework with its hand-in, and NEVER a «mark done» button", async () => {
    answerAssignment();

    await open();

    expect(await screen.findByText("حلّ التمارين ١–٥.")).toBeDefined();
    expect(get).toHaveBeenCalledWith("/assignments/hw-1");
    expect(screen.getByLabelText("إجابتك")).toBeDefined();
    expect(screen.getByText(/يكتمل هذا العنصر تلقائياً عند تسليم الواجب/)).toBeDefined();
    expect(screen.queryByRole("button", { name: "علِّمه مكتملاً" })).toBeNull();
  });

  it("hands the work in to the homework's own door, then re-reads the item", async () => {
    answerAssignment();
    upload.mockResolvedValue({ data: {} });

    await open();

    fireEvent.change(await screen.findByLabelText("إجابتك"), { target: { value: "الحلّ" } });

    get.mockClear();
    answerAssignment(true);

    fireEvent.click(screen.getByRole("button", { name: "سلّم" }));

    await waitFor(() => {
      expect(upload).toHaveBeenCalledWith("/assignments/hw-1/submissions", expect.any(FormData));
    });

    // The completion is the SERVER's answer, re-read — never flipped here.
    await waitFor(() => {
      expect(get).toHaveBeenCalledWith("/learn/lessons/l-1");
    });
    expect(await screen.findByText("✓ اكتمل هذا العنصر.")).toBeDefined();
  });

  it("offers the AUTHOR no hand-in — nothing of theirs belongs in the marking queue", async () => {
    answerAssignment(false, null);

    await open();

    expect(await screen.findByRole("link", { name: "واجباتي" })).toBeDefined();
    expect(screen.queryByLabelText("إجابتك")).toBeNull();
    expect(get).not.toHaveBeenCalledWith("/assignments/hw-1");
  });

  it("says why the homework could not be read instead of leaving the slot blank", async () => {
    answerAssignment();
    get.mockImplementation((path: string) => {
      if (path.includes("/curriculum")) return Promise.resolve(tree());
      if (path.startsWith("/assignments/")) return Promise.reject(new Error("boom"));

      return Promise.resolve({
        lesson: {
          ...ARTICLE,
          type: "assignment",
          may_self_complete: false,
          reference: HOMEWORK_REFERENCE,
        },
        can_access: true,
        blocked_reason: null,
        blocked_message: null,
        blocked_by_title: null,
        enrollment_uuid: "e-1",
      });
    });

    await open();

    expect(await screen.findByText("تعذّر تحميل الواجب")).toBeDefined();
  });
});
