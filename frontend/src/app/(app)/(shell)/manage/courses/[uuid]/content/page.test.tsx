import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| Spec 033 · US1 · US3 — the two browser dialogs this screen used to open.
|
| ⚠️ IT HAD NO TEST OF ANY KIND, which is why both sat there for so long. One of
| them was `window.prompt` — a grey input box asking for an Arabic title, laid
| out left-to-right, unstyleable — and the other asked a permanent deletion in
| exactly the same box.
|
| ⚠️ `TreeOutline` IS STUBBED DOWN TO ITS TWO CALLBACKS. This file is about the
| QUESTIONS the page asks, not about how the tree renders; a real outline would
| drag its own status badges, move controls and publish buttons into every
| failure message here.
*/
const createLesson = vi.fn();
const deleteLesson = vi.fn();
const tree = vi.fn();

vi.mock("@/lib/courses", () => ({
  courses: {
    tree: (...args: unknown[]) => tree(...args),
    createLesson: (...args: unknown[]) => createLesson(...args),
    deleteLesson: (...args: unknown[]) => deleteLesson(...args),
    deleteSection: vi.fn(),
    deleteChapter: vi.fn(),
    createSection: vi.fn(),
    createChapter: vi.fn(),
    renameSection: vi.fn(),
    renameChapter: vi.fn(),
    renameLesson: vi.fn(),
    reorderSections: vi.fn(),
    reorderChapters: vi.fn(),
    reorderLessons: vi.fn(),
    setStatus: vi.fn(),
    publishTree: vi.fn(),
  },
  moveWithin: (list: string[]) => list,
}));

vi.mock("@/components/courses/TreeOutline", () => ({
  TreeOutline: ({
    onDelete,
    onAddLesson,
  }: {
    onDelete: (kind: string, uuid: string, title: string) => void;
    onAddLesson: (chapter: { uuid: string }) => void;
  }) => (
    <div>
      <button type="button" onClick={() => onDelete("lesson", "lesson-plain", "الدرس الأول")}>
        احذف-درساً
      </button>
      <button type="button" onClick={() => onDelete("lesson", "lesson-recording", "حصة السبت")}>
        احذف-تسجيلاً
      </button>
      <button type="button" onClick={() => onAddLesson({ uuid: "chapter-1" })}>
        أضِف-عنصراً
      </button>
    </div>
  ),
}));

vi.mock("@/components/courses/LessonEditor", () => ({ LessonEditor: () => null }));
// Reduced to its confirm press: the question here is what the page says AFTER.
vi.mock("@/components/courses/PublishImpactDialog", () => ({
  PublishImpactDialog: ({
    onConfirm,
  }: {
    onConfirm: (preview: { structure_version: number; items: unknown[] }) => void;
  }) => (
    <button type="button" onClick={() => onConfirm({ structure_version: 3, items: [] })}>
      أكّد-النشر
    </button>
  ),
}));

const { default: CourseContentPage } = await import("./page");

/**
 * One plain item and one RECORDING — the recording is what makes the delete
 * question two questions (spec 016 · FR-053), and a fixture without it lets a
 * single merged sentence pass.
 */
const TREE = {
  status: "published",
  structure_version: 3,
  sections: [
    {
      uuid: "section-1",
      title: "قسم",
      status: "draft",
      chapters: [
        {
          uuid: "chapter-1",
          title: "فصل",
          status: "draft",
          lessons: [
            { uuid: "lesson-plain", title: "الدرس الأول", status: "draft", is_recording: false },
            { uuid: "lesson-recording", title: "حصة السبت", status: "draft", is_recording: true },
          ],
        },
      ],
    },
  ],
};

async function openPage(courseStatus = "published") {
  tree.mockResolvedValue({ ...TREE, status: courseStatus });

  await act(async () => {
    render(<CourseContentPage params={Promise.resolve({ uuid: "course-1" })} />);
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("deleting a node", () => {
  it("asks in our own window, with the shipped sentence unchanged", async () => {
    await openPage();

    fireEvent.click(screen.getByText("احذف-درساً"));

    // Letter for letter (SC-005) — the container changed, the wording did not.
    expect(screen.getByText("سيُحذف «الدرس الأول» وكل ما بداخله. متابعة؟")).toBeTruthy();
    expect(deleteLesson).not.toHaveBeenCalled();
  });

  it("gives a RECORDING its own sentence", async () => {
    /*
    | ⛔ FR-053. Deleting a recording is not «removing an item from a course»: it
    | is the only route back to that hour for everyone who attended it. Merging
    | the two sentences «to simplify» deletes the requirement.
    */
    await openPage();

    fireEvent.click(screen.getByText("احذف-تسجيلاً"));

    expect(
      screen.getByText(
        "«حصة السبت» تسجيل حصة، وهو الطريق الوحيد لمن حضرها إليه. حذفه يقطعه عنهم نهائياً. متابعة؟",
      ),
    ).toBeTruthy();
  });

  it("cancelling deletes nothing", async () => {
    await openPage();

    fireEvent.click(screen.getByText("احذف-درساً"));
    fireEvent.click(screen.getByText("إلغاء"));

    expect(deleteLesson).not.toHaveBeenCalled();
  });

  it("confirming deletes exactly once", async () => {
    deleteLesson.mockResolvedValue(TREE);

    await openPage();

    fireEvent.click(screen.getByText("احذف-درساً"));

    await act(async () => {
      fireEvent.click(screen.getByText("احذف"));
    });

    expect(deleteLesson).toHaveBeenCalledTimes(1);
    expect(deleteLesson).toHaveBeenCalledWith("course-1", "lesson-plain");
  });
});

describe("adding an item", () => {
  it("asks for the title in a field of ours", async () => {
    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));

    expect(screen.getByLabelText("عنوان العنصر الجديد")).toBeTruthy();
    expect(createLesson).not.toHaveBeenCalled();
  });

  it("refuses an empty title WITH A REASON, and keeps the window open", async () => {
    /*
    | ⛔ THE OLD `window.prompt` BRANCH RETURNED SILENTLY on an empty string.
    | «Nothing happened» in answer to a correct press is the defect people report
    | a week later as «the button does not work», with nothing in it to go on.
    */
    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));
    fireEvent.click(screen.getByText("أضِف"));

    expect(createLesson).not.toHaveBeenCalled();
    expect(screen.getByText("اكتب عنواناً للعنصر أولاً.")).toBeTruthy();
    expect(screen.getByLabelText("عنوان العنصر الجديد")).toBeTruthy();
  });

  it("refuses whitespace exactly as it refuses empty", async () => {
    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));
    fireEvent.change(screen.getByLabelText("عنوان العنصر الجديد"), { target: { value: "   " } });
    fireEvent.click(screen.getByText("أضِف"));

    expect(createLesson).not.toHaveBeenCalled();
  });

  it("creates a draft article from the title, trimmed", async () => {
    createLesson.mockResolvedValue(TREE);

    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));
    fireEvent.change(screen.getByLabelText("عنوان العنصر الجديد"), {
      target: { value: "  مقدّمة  " },
    });

    await act(async () => {
      fireEvent.click(screen.getByText("أضِف"));
    });

    expect(createLesson).toHaveBeenCalledTimes(1);
    expect(createLesson).toHaveBeenCalledWith("course-1", {
      chapter_uuid: "chapter-1",
      title: "مقدّمة",
      // Article is the one type that needs nothing uploaded or referenced, so a
      // teacher can start writing immediately.
      type: "article",
    });
  });
});

describe("Enter in the new-item window", () => {
  it("submits, as the section and chapter inputs already do", async () => {
    createLesson.mockResolvedValue(TREE);

    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));
    const field = screen.getByLabelText("عنوان العنصر الجديد");
    fireEvent.change(field, { target: { value: "مقدّمة" } });

    await act(async () => {
      fireEvent.submit(field.closest("form") as HTMLFormElement);
    });

    expect(createLesson).toHaveBeenCalledTimes(1);
  });

  it("still refuses an empty title on Enter", async () => {
    await openPage();

    fireEvent.click(screen.getByText("أضِف-عنصراً"));
    fireEvent.submit(
      screen.getByLabelText("عنوان العنصر الجديد").closest("form") as HTMLFormElement,
    );

    expect(createLesson).not.toHaveBeenCalled();
    expect(screen.getByText("اكتب عنواناً للعنصر أولاً.")).toBeTruthy();
  });
});

describe("the message after «نشر كل المسودّات»", () => {
  /*
  | ⛔ It said «صارت مرئية لطلابك الآن» while the COURSE itself was still a
  | draft — so no student could see anything, and the teacher was told they could.
  */
  async function publishAll(courseStatus: string) {
    await openPage(courseStatus);

    fireEvent.click(screen.getByRole("button", { name: "نشر كل المسودّات" }));
    await act(async () => {
      fireEvent.click(screen.getByText("أكّد-النشر"));
    });
  }

  it("says the course is still a draft when it is", async () => {
    await publishAll("draft");

    expect(
      screen.getByText(
        "نُشرت المسودّات، لكن الكورس نفسه ما زال مسودّة — انشره من «تعديل الكورس» ليراه طلابك.",
      ),
    ).toBeTruthy();
    expect(screen.queryByText("نُشرت المسودّات — صارت مرئية لطلابك الآن.")).toBeNull();
  });

  it("says students can see it when the course is published", async () => {
    await publishAll("published");

    expect(screen.getByText("نُشرت المسودّات — صارت مرئية لطلابك الآن.")).toBeTruthy();
  });
});
