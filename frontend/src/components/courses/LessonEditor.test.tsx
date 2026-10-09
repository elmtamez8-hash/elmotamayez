import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { LessonEditor } from "./LessonEditor";

/*
| ٠٣٢ · FR-007 — «فيديو مُضمَّن» محجوبٌ عن درسٍ ليسَ مفتوحاً.
|
| ⚠️ وهو **النصفُ الثاني** من الحرس، لا الحرس. الخادمُ يرفضُ نشرَ مُضمَّنٍ مقفَلٍ
| مهما فعلَت هذه القائمة — وحجبُ ضابطٍ ليس حرساً، وقارئٌ ثانٍ للواجهةِ البرمجيّةِ
| لا يحملُ منها شيئاً.
*/

const lessonFetch = vi.fn();
const typeChangePreview = vi.fn();
const changeLessonType = vi.fn();
const referenceTargets = vi.fn();
const updateLesson = vi.fn();
const setTrialLesson = vi.fn();

vi.mock("@/lib/courses", () => ({
  courses: {
    lesson: (...args: unknown[]) => lessonFetch(...args),
    updateLesson: (...args: unknown[]) => updateLesson(...args),
    referenceTargets: (...args: unknown[]) => referenceTargets(...args),
    typeChangePreview: (...args: unknown[]) => typeChangePreview(...args),
    changeLessonType: (...args: unknown[]) => changeLessonType(...args),
    setTrialLesson: (...args: unknown[]) => setTrialLesson(...args),
  },
}));

// يرفعُ ملفّاً ويطرقُ الشبكة، ولا شأنَ له بقائمةِ الأنواع.
vi.mock("./AttachmentsPanel", () => ({ AttachmentsPanel: () => null }));

// The rich editor (Tiptap) is its own chunk with its own tests
// (`RichMarkdownEditor.test.tsx`); here it is a labelled field that, like the
// real one, reads `value` once on mount and hands Markdown back.
vi.mock("@/components/ui/RichMarkdownEditor", () => ({
  RichMarkdownEditor: ({
    id,
    label,
    value,
    onChange,
  }: {
    id: string;
    label: string;
    value: string;
    onChange: (markdown: string) => void;
  }) => (
    <div>
      <label htmlFor={id}>{label}</label>
      <textarea id={id} defaultValue={value} onChange={(event) => onChange(event.target.value)} />
    </div>
  ),
}));

const BASE = {
  uuid: "l-1",
  chapter_uuid: "c-1",
  section_uuid: "s-1",
  title: "الحصّة",
  type: "article",
  type_label: "مقالة",
  status: "draft",
  status_label: "مسودّة",
  content: "نصّ",
  content_html: "<p>نصّ</p>",
  external_url: null,
  is_completable: true,
  is_recording: false,
  family: "inline",
  asset_kind: null,
  order: 0,
  duration_seconds: 0,
  is_preview: false,
  is_free: false,
  reference: null,
  reference_missing: false,
  exam_gate: null,
  attachments: [],
  asset: null,
  is_trial: false,
  trial_refusal: null,
  can_choose_trial: false,
  trial_status: null,
};

async function open(over: Record<string, unknown> = {}) {
  lessonFetch.mockResolvedValue({ ...BASE, ...over });

  await act(async () => {
    render(
      <LessonEditor courseUuid="c" lessonUuid="l-1" onSaved={() => {}} onClose={() => {}} />,
    );
  });
}

function typeOptionLabels(): string[] {
  const select = screen.getByLabelText(/نوع العنصر/) as HTMLSelectElement;

  return Array.from(select.options).map((option) => option.text);
}

beforeEach(() => vi.clearAllMocks());

describe("the type picker", () => {
  it("hides «فيديو مُضمَّن» from a lesson that is not open", async () => {
    await open({ is_preview: false, is_free: false });

    expect(typeOptionLabels()).not.toContain("فيديو مُضمَّن");
  });

  it("offers it once the lesson is marked available without enrolment", async () => {
    await open({ is_preview: true });

    expect(typeOptionLabels()).toContain("فيديو مُضمَّن");
  });

  it("offers it on a free lesson too — either mark is enough", async () => {
    // ⚠️ «مفتوح» هو `is_preview || is_free`، كما يقرؤُهما مُصدِرُ منحةِ التشغيلِ
    // حرفاً بحرف. هجاءٌ ثانٍ لسؤالٍ واحدٍ هو العطبُ الذي دفعَ ثمنَه هذا المستودَع.
    await open({ is_preview: false, is_free: true });

    expect(typeOptionLabels()).toContain("فيديو مُضمَّن");
  });

  it("says where the missing option comes from, so its absence is not a mystery", async () => {
    await open({ is_preview: false, is_free: false });

    expect(screen.getByText(/«فيديو مُضمَّن» يظهر بعد تعليم العنصر/)).toBeDefined();
  });
});

/*
| Spec 033 · US2 — the type change is confirmed in OUR window, after the cost has
| been read from the server.
|
| ⚠️ THE ORDER IS THE REQUIREMENT (FR-010), not an implementation detail. A
| confirmation that lists nothing is a confirmation people click through, so the
| preview is fetched BEFORE the window opens and the sentence names what
| disappears. Asking first would be a question about an answer nobody has yet.
*/
async function pickType(label: string): Promise<void> {
  const select = screen.getByLabelText(/نوع العنصر/) as HTMLSelectElement;
  const option = Array.from(select.options).find((each) => each.text === label);

  await act(async () => {
    fireEvent.change(select, { target: { value: option?.value } });
  });
}

describe("changing the type", () => {
  it("names what disappears, and changes nothing until it is confirmed", async () => {
    typeChangePreview.mockResolvedValue({ type_label: "فيديو", losses: ["النصّ المكتوب"] });

    await open();
    await pickType("فيديو");

    // Letter for letter (SC-005): this spec moves the question, never rewords it.
    expect(
      screen.getByText("سيتحوّل العنصر إلى «فيديو» وسيُفقد: النصّ المكتوب"),
    ).toBeTruthy();
    expect(changeLessonType).not.toHaveBeenCalled();
  });

  it("cancelling leaves the type alone AND leaves the control usable", async () => {
    /*
    | ⛔ THE SECOND HALF IS THE ONE THAT SHIPS BROKEN. `busy` disables the whole
    | editor while the preview is in flight; left up after a cancel, the teacher
    | is returned to a dead select that says nothing about why — the «الزرّ لا
    | يعمل» report, arriving a week later with no clue in it.
    */
    typeChangePreview.mockResolvedValue({ type_label: "فيديو", losses: [] });

    await open();
    await pickType("فيديو");

    await act(async () => {
      fireEvent.click(screen.getByText("إلغاء"));
    });

    expect(changeLessonType).not.toHaveBeenCalled();

    const select = screen.getByLabelText(/نوع العنصر/) as HTMLSelectElement;

    expect(select.disabled).toBe(false);
  });

  it("confirming changes the type once", async () => {
    typeChangePreview.mockResolvedValue({ type_label: "فيديو", losses: [] });
    // ⚠️ `run()` writes the RETURNED lesson straight into state, so a mock that
    // resolves `undefined` crashes the component after a successful change —
    // a red test over correct code, on the one case that matters most.
    changeLessonType.mockResolvedValue({ ...BASE, type: "video", type_label: "فيديو" });

    await open();
    await pickType("فيديو");

    await act(async () => {
      fireEvent.click(screen.getByText("غيّر النوع"));
    });

    expect(changeLessonType).toHaveBeenCalledTimes(1);
  });

  it("a failed preview opens NO window and changes nothing", async () => {
    /*
    | The refusal must not become a question. Without the cost there is nothing
    | to put in the sentence, and a window saying «متابعة؟» over an unknown loss
    | is worse than no window at all.
    */
    typeChangePreview.mockImplementation(() => {
      const failure = Promise.reject(new Error("تعذّر"));

      // See `AttachmentsPanel.test.tsx` for why the mock's own copy is consumed.
      failure.catch(() => undefined);

      return failure;
    });

    await open();
    await pickType("فيديو");

    expect(screen.queryByText("غيّر النوع")).toBeNull();
    expect(changeLessonType).not.toHaveBeenCalled();
  });
});

/*
| An assignment item is PLACED from here now — the picker lists the course's
| published homework and saves the one chosen as the item's reference.
|
| ⚠️ The first case guards the sentence that stood here while the type could
| not be placed («الواجب لا يُربَط بالمنهج بعد»): left over a working picker it
| is the «not yet» message this editor was rebuilt to stop telling.
*/
describe("an assignment item", () => {
  const assignmentItem = {
    type: "assignment",
    type_label: "واجب",
    family: "reference",
    content: null,
    content_html: "",
    reference: null,
  };

  it("offers the type as a real choice, with no «not linked yet» sentence left over", async () => {
    referenceTargets.mockResolvedValue({ exams: [], sessions: [], assignments: [] });

    await open(assignmentItem);

    const select = screen.getByLabelText(/نوع العنصر/) as HTMLSelectElement;
    const option = Array.from(select.options).find((each) => each.value === "assignment");

    expect(option?.disabled).toBe(false);
    expect(screen.queryByText(/لا يُربَط بالمنهج/)).toBeNull();
  });

  it("lists this course's published homework and saves the one chosen", async () => {
    referenceTargets.mockResolvedValue({
      exams: [],
      sessions: [],
      assignments: [
        { uuid: "hw-1", title: "واجب الكسور", due_at: null, points: 10 },
        { uuid: "hw-2", title: "واجب الهندسة", due_at: null, points: 20 },
      ],
    });
    updateLesson.mockResolvedValue({ ...BASE, ...assignmentItem });

    await open(assignmentItem);

    const picker = screen.getByLabelText("الواجب") as HTMLSelectElement;
    const labels = Array.from(picker.options).map((each) => each.text);

    expect(labels).toContain("واجب الكسور");
    expect(labels).toContain("واجب الهندسة");
    // Said before publish, not as a refusal after it.
    expect(screen.getByText("هذا العنصر لا يشير إلى واجب بعد")).toBeTruthy();

    await act(async () => {
      fireEvent.change(picker, { target: { value: "hw-2" } });
    });

    expect(updateLesson).toHaveBeenCalledWith("c", "l-1", { reference_uuid: "hw-2" });
  });

  it("sends the teacher to write homework when the course has none published", async () => {
    referenceTargets.mockResolvedValue({ exams: [], sessions: [], assignments: [] });

    await open(assignmentItem);

    expect(screen.getByText("لا واجب منشور في هذا الكورس")).toBeTruthy();
    expect(screen.getByRole("link", { name: "واجباتي" }).getAttribute("href")).toBe(
      "/manage/assignments",
    );
  });
});

/*
| ⛔ «نشر كل المسودّات» من الشجرة تركَ رأسَ المحرِّرِ المفتوحِ يقولُ «مقالة — مسودّة»
| بينما الشجرةُ بجانبِه تقولُ «منشور» (2026-09-26). الصفحةُ ترفعُ `revision` بعدَ
| النشر، فيُعيدُ المحرِّرُ قراءةَ العنصرِ — ولا يمسُّ ما يكتبُه المدرّسُ في الحقل.
*/
describe("a publish from the tree", () => {
  it("re-reads the item's status and keeps the text being typed", async () => {
    lessonFetch.mockResolvedValue(BASE);

    const view = await act(async () =>
      render(
        <LessonEditor courseUuid="c" lessonUuid="l-1" revision={0} onSaved={() => {}} onClose={() => {}} />,
      ),
    );

    expect(screen.getByText(/مقالة — مسودّة/)).toBeTruthy();

    const field = screen.getByLabelText(/نصّ المقالة/) as HTMLTextAreaElement;

    await act(async () => {
      fireEvent.change(field, { target: { value: "نصّ لم يُحفظ بعد" } });
    });

    lessonFetch.mockResolvedValue({ ...BASE, status: "published", status_label: "منشور" });

    await act(async () => {
      view.rerender(
        <LessonEditor courseUuid="c" lessonUuid="l-1" revision={1} onSaved={() => {}} onClose={() => {}} />,
      );
    });

    expect(screen.getByText(/مقالة — منشور/)).toBeTruthy();
    expect((screen.getByLabelText(/نصّ المقالة/) as HTMLTextAreaElement).value).toBe(
      "نصّ لم يُحفظ بعد",
    );
  });
});

/*
| ⛔ A TOGGLE MUST NOT TAKE BACK WHAT IS BEING TYPED (review of #310). The body
| editor reads its value once; `run()` used to write the server's OLD body into
| state after ANY save — so ticking «متاح بلا تسجيل» mid-edit left the new words
| on screen and the old ones in state, and the next «حفظ» sent the old ones.
*/
describe("a toggle while the body is being edited", () => {
  it("keeps the typed body for the next save", async () => {
    await open();

    await act(async () => {
      fireEvent.change(screen.getByLabelText(/نصّ المقالة/), { target: { value: "نصّ جديد لم يُحفظ" } });
    });

    // The server answers the toggle with the body it still holds.
    updateLesson.mockResolvedValue({ ...BASE, is_preview: true });

    await act(async () => {
      fireEvent.click(document.getElementById("preview-l-1") as HTMLElement);
    });

    updateLesson.mockResolvedValue({ ...BASE, content: "نصّ جديد لم يُحفظ" });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "حفظ" }));
    });

    expect(updateLesson).toHaveBeenLastCalledWith(
      "c",
      "l-1",
      expect.objectContaining({ content: "نصّ جديد لم يُحفظ" }),
    );
  });
});

/*
| Spec 040 — the course's «حصة تجريبية». The switch is the TEACHER's: shown from
| the server's `can_choose_trial`, never from the role, and a clear names the
| lesson the reader saw so a stale tab cannot wipe a newer pick.
*/
describe("the trial switch", () => {
  it("is absent when the reader does not decide it (an assistant)", async () => {
    await open({ type: "embed", can_choose_trial: false });

    expect(screen.queryByLabelText(/الحصة التجريبية لهذا الكورس/)).toBeNull();
  });

  it("marks the lesson and reads it back", async () => {
    setTrialLesson.mockResolvedValue({ data: { trial_lesson: { uuid: "l-1" }, trial_status: "visible" } });
    await open({ type: "embed", can_choose_trial: true });
    lessonFetch.mockResolvedValue({ ...BASE, type: "embed", can_choose_trial: true, is_trial: true, trial_status: "visible" });

    await act(async () => {
      fireEvent.click(screen.getByLabelText(/الحصة التجريبية لهذا الكورس/));
    });

    expect(setTrialLesson).toHaveBeenCalledWith("c", "l-1", undefined);
    expect((screen.getByLabelText(/الحصة التجريبية لهذا الكورس/) as HTMLInputElement).checked).toBe(true);
  });

  it("clears by naming the lesson it saw as the trial", async () => {
    setTrialLesson.mockResolvedValue({ data: { trial_lesson: null, trial_status: null } });
    await open({ type: "embed", can_choose_trial: true, is_trial: true, trial_status: "visible" });

    await act(async () => {
      fireEvent.click(screen.getByLabelText(/الحصة التجريبية لهذا الكورس/));
    });

    expect(setTrialLesson).toHaveBeenCalledWith("c", null, "l-1");
  });

  it("shows why a lesson cannot be the trial, on a disabled switch", async () => {
    await open({ type: "article", can_choose_trial: true, trial_refusal: "الحصة التجريبية فيديو." });

    expect((screen.getByLabelText(/الحصة التجريبية لهذا الكورس/) as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText("الحصة التجريبية فيديو.")).toBeDefined();
  });

  it("says why visitors do not see the trial yet", async () => {
    await open({ type: "video", can_choose_trial: true, is_trial: true, trial_status: "processing" });

    expect(screen.getByText("لن تظهر للزوار حتى ينتهي تجهيز الفيديو.")).toBeDefined();
  });
});
