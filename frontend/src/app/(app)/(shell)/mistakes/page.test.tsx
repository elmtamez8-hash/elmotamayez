import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import MistakesPage from "./page";

/*
| دفترُ الأخطاء: المُصلَحُ يظهرُ بضغطةٍ واحدةٍ، والصوابُ يُميَّزُ عن الخطأِ بغيرِ اللون.
|
| ⚠️ الزرُّ كان واحداً يُعيدُ تسميةَ نفسِه — «أظهر ما أصلحته» ثمّ «القائم فقط» —
| فالنصُّ يصفُ الحالةَ الأخرى، ولا شيءَ على الشاشةِ يقولُ أيَّهما يقرأُ الآن.
*/

const list = vi.fn();
const filters = vi.fn();

vi.mock("@/lib/mistakes", () => ({
  mistakes: {
    list: (given: Record<string, unknown>) => list(given),
    // ⚠️ THE BAR'S OPTIONS COME FROM THE SERVER, so the page asks for them on
    // mount. A mock missing this is not a missing stub — it is the page
    // throwing inside its own effect, which reads as seven unrelated failures.
    filters: (includeResolved?: boolean) => filters(includeResolved),
  },
}));

function mistake(overrides: Record<string, unknown> = {}) {
  return {
    uuid: "m-1",
    question: {
      uuid: "q-1",
      type: "mcq",
      content: "كم يساوي ٣ × ٧ ؟",
      explanation: "حاصلُ ضربِ ٣ في ٧ هو ٢١.",
      concept: { uuid: "c-1", name: "أساسيّات المعادلات" },
      lesson: null,
    },
    your_answer: ["٢٤"],
    correct_answer: ["٢١"],
    teacher: { uuid: "w-1", name: "أ. سامي" },
    is_resolved: false,
    times_wrong: 1,
    answered_at: "2026-08-20T09:00:00+00:00",
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  list.mockResolvedValue({ data: [mistake()], meta: { total: 1, current_page: 1, last_page: 1 } });
  // Every facet empty, which is the shape that draws no bar at all — the
  // assertions below are about the list, not about the filtering.
  filters.mockResolvedValue({
    teachers: [],
    courses: [],
    exams: [],
    concepts: [],
    has_standing: false,
  });
});

describe("MistakesPage", () => {
  it("asks for the standing mistakes first", async () => {
    render(<MistakesPage />);

    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(list.mock.calls[0][0]).toEqual({ include_resolved: false });
  });

  it("fetches the fixed ones when «الكل» is pressed", async () => {
    render(<MistakesPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    fireEvent.click(screen.getByRole("button", { name: "الكل" }));

    await waitFor(() => {
      expect(list.mock.calls.at(-1)?.[0]).toEqual({ include_resolved: true });
    });
  });

  it("keeps both choices on screen so the reader can see which one they are in", async () => {
    /*
     | ⚠️ THE WHOLE POINT OF THE SEGMENTED PAIR. One button that renamed itself
     | described the state the reader was NOT in, and there was nothing else on
     | the page to tell them apart — the list looks the same either way when a
     | student has no fixed mistakes yet.
     */
    render(<MistakesPage />);
    await waitFor(() => expect(list).toHaveBeenCalled());

    expect(screen.getByRole("button", { name: "القائم" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "الكل" })).toBeTruthy();
  });

  it("labels the wrong answer and the right one in words, not by colour alone", async () => {
    // The whole page is a wrong answer beside a right one. A reader who cannot
    // separate the two tints would see two identical blocks.
    render(<MistakesPage />);

    expect(await screen.findByText("إجابتك")).toBeTruthy();
    expect(screen.getByText("الصواب")).toBeTruthy();
  });

  it("says a blank answer is blank rather than showing nothing", async () => {
    // An unanswered question is the strongest evidence of a gap on the page.
    list.mockResolvedValue({ data: [mistake({ your_answer: [] })], meta: { total: 1 } });

    render(<MistakesPage />);

    expect(await screen.findByText("تركته بلا إجابة")).toBeTruthy();
  });

  it("uses the Arabic dual for two", async () => {
    // «٢ مرّات» is not an Arabic sentence, and two is by far the commonest count.
    list.mockResolvedValue({ data: [mistake({ times_wrong: 2 })], meta: { total: 1 } });

    render(<MistakesPage />);

    expect(await screen.findByText("أخطأت فيه مرّتين")).toBeTruthy();
  });

  /*
   | ⚠️ «IS THERE ANYTHING TO PRACTISE» IS THE PICKER'S ANSWER, NOT A COUNT OF
   | THE ROWS ON SCREEN. Counting unresolved rows counts the current page of the
   | current filter — so «الكل» plus a teacher, on a page of fixed questions,
   | would hide the button while other teachers still hold standing mistakes.
   | The facets are derived from the standing set, so an empty teacher list means
   | there is genuinely nothing to build a paper from, and building from nothing
   | is a 422 the student cannot act on.
  */
  it("offers the practice paper only while something is still standing", async () => {
    list.mockResolvedValue({ data: [mistake({ is_resolved: true })], meta: { total: 1 } });

    render(<MistakesPage />);

    await screen.findByText("أصلحته");

    expect(screen.queryByRole("link", { name: /اختبرني/ })).toBeNull();
  });

  it("still offers it when the page shown is filtered down to fixed questions", async () => {
    // The row on screen is resolved; the notebook still holds standing mistakes,
    // which is exactly what the facets report.
    list.mockResolvedValue({ data: [mistake({ is_resolved: true })], meta: { total: 1 } });
    filters.mockResolvedValue({
      teachers: [{ uuid: "w-1", label: "أ. سامي" }],
      courses: [],
      exams: [],
      concepts: [],
      has_standing: true,
    });

    render(<MistakesPage />);

    expect(await screen.findByRole("link", { name: /اختبرني/ })).toBeTruthy();
  });

  /*
   | ⚠️ THE NOTEBOOK SPANS TEACHERS NOW, SO EVERY ROW HAS TO SAY WHOSE IT IS.
   | It refused to answer a real student at all before — «اختر مساحة عمل», for a
   | person who is a member of none — and the requirement it was enforcing that
   | way is kept by the row rather than by the query: nobody is shown half their
   | mistakes called all of them, and no question is read inside another
   | teacher's context.
  */
  it("names the teacher on every row", async () => {
    render(<MistakesPage />);

    expect(await screen.findByText("أ. سامي")).toBeTruthy();
  });

  it("draws the bar from what the server offers, and asks again with the choice", async () => {
    filters.mockResolvedValue({
      teachers: [
        { uuid: "w-1", label: "أ. سامي" },
        { uuid: "w-2", label: "أ. هدى" },
      ],
      courses: [],
      exams: [],
      concepts: [],
      has_standing: true,
    });

    render(<MistakesPage />);

    const teacher = await screen.findByRole("combobox", { name: /المدرّس/ });

    fireEvent.change(teacher, { target: { value: "w-2" } });

    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith({ teacher: "w-2", include_resolved: false }),
    );
  });

  /*
   | ⚠️ THE PRACTICE LINK CARRIES THE FILTER, AND WITHOUT IT THE BUTTON IS AN
   | ERROR PAGE. A revision paper belongs to ONE teacher — one bank, one
   | withholding rule — so with several to choose from and none named the server
   | refuses rather than guessing. The reader looking at a narrowed notebook has
   | already answered that question.
  */
  it("passes the chosen teacher on to the revision paper", async () => {
    filters.mockResolvedValue({
      teachers: [
        { uuid: "w-1", label: "أ. سامي" },
        { uuid: "w-2", label: "أ. هدى" },
      ],
      courses: [],
      exams: [],
      concepts: [],
      has_standing: true,
    });

    render(<MistakesPage />);

    fireEvent.change(await screen.findByRole("combobox", { name: /المدرّس/ }), {
      target: { value: "w-2" },
    });

    await waitFor(() =>
      expect(screen.getByRole("link", { name: /اختبرني/ }).getAttribute("href")).toBe(
        "/mistakes/practice?teacher=w-2",
      ),
    );
  });

  /*
   | ⚠️ «لا أخطاء» IS FALSE UNDER A FILTER, and saying it contradicts the list the
   | reader just saw while hiding the one action that helps.
  */
  it("says the filter found nothing rather than claiming the notebook is empty", async () => {
    filters.mockResolvedValue({
      teachers: [
        { uuid: "w-1", label: "أ. سامي" },
        { uuid: "w-2", label: "أ. هدى" },
      ],
      courses: [],
      exams: [],
      concepts: [],
      has_standing: true,
    });

    render(<MistakesPage />);

    list.mockResolvedValue({ data: [], meta: { total: 0 } });

    fireEvent.change(await screen.findByRole("combobox", { name: /المدرّس/ }), {
      target: { value: "w-2" },
    });

    expect(await screen.findByText("لا نتائج بهذه التصفية")).toBeTruthy();
  });

  /*
   | ⚠️ THE BAR FOLLOWS «القائم» ⇄ «الكل», AND A REAL DATABASE IS WHAT FOUND IT.
   |
   | Derived from the standing set alone it vanished for anybody who had fixed
   | everything — «القائم» is empty and rightly bare, but «الكل» then listed the
   | whole notebook with nothing to filter it by. That is the shape of the demo
   | data, so the screen offered no filters in either view on the only account
   | anybody was looking at.
  */
  it("asks for the bar again when the view switches to «الكل»", async () => {
    render(<MistakesPage />);

    await waitFor(() => expect(filters).toHaveBeenCalledTimes(1));

    fireEvent.click(screen.getByRole("button", { name: "الكل" }));

    await waitFor(() => expect(filters).toHaveBeenCalledTimes(2));
  });

  /*
   | ⚠️ AND THE PRACTICE BUTTON DOES NOT FOLLOW IT. `has_standing` answers «is
   | there anything to practise», which the view does not change — a paper built
   | from nothing is a 422 the student cannot act on.
  */
  it("keeps the practice button hidden under «الكل» when nothing is standing", async () => {
    filters.mockResolvedValue({
      teachers: [{ uuid: "w-1", label: "أ. سامي" }],
      courses: [],
      exams: [],
      concepts: [],
      has_standing: false,
    });

    render(<MistakesPage />);

    expect(await screen.findByRole("combobox", { name: /المدرّس/ })).toBeTruthy();
    expect(screen.queryByRole("link", { name: /اختبرني/ })).toBeNull();
  });
});
