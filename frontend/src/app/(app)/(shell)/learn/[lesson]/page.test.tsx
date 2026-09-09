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

vi.mock("@/lib/api", async (importOriginal) => ({
  // ⚠️ `importOriginal` وليسَ كائناً عارياً: الصفحةُ تستوردُ `ApiError`
  // كذلك، وتمويهٌ يسقطُهُ يجعلُ `err instanceof ApiError` يرمي على
  // `undefined` في منتصفِ الرسم — فيخرجُ جسمٌ فارغٌ تماماً وتقولُ
  // الرسالةُ «لم أجدِ الزرّ» لا «لم يُرسمْ شيء».
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { get: (path: string) => get(path), post: (path: string) => post(path) },
}));

vi.mock("@/lib/media", () => ({ media: { grant: vi.fn() } }));

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

function answer(over: Record<string, unknown> = {}, lesson: Record<string, unknown> = {}) {
  get.mockResolvedValue({
    lesson: { ...ARTICLE, ...lesson },
    can_access: true,
    blocked_reason: null,
    blocked_message: null,
    blocked_by_title: null,
    enrollment_uuid: "e-1",
    ...over,
  });
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

    await screen.findByText("الدرس الأول");

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
