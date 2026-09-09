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

vi.mock("@/lib/courses", () => ({
  courses: {
    lesson: (...args: unknown[]) => lessonFetch(...args),
    updateLesson: vi.fn(),
    typeChangePreview: (...args: unknown[]) => typeChangePreview(...args),
    changeLessonType: (...args: unknown[]) => changeLessonType(...args),
  },
}));

// يرفعُ ملفّاً ويطرقُ الشبكة، ولا شأنَ له بقائمةِ الأنواع.
vi.mock("./AttachmentsPanel", () => ({ AttachmentsPanel: () => null }));

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
