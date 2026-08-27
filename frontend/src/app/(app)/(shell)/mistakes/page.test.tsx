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

vi.mock("@/lib/mistakes", () => ({
  mistakes: { list: (filters: Record<string, unknown>) => list(filters) },
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
    is_resolved: false,
    times_wrong: 1,
    answered_at: "2026-08-20T09:00:00+00:00",
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  list.mockResolvedValue({ data: [mistake()], meta: { total: 1, current_page: 1, last_page: 1 } });
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

  it("offers the practice paper only while something is still standing", async () => {
    list.mockResolvedValue({ data: [mistake({ is_resolved: true })], meta: { total: 1 } });

    render(<MistakesPage />);

    await screen.findByText("أصلحته");

    // Building a paper from nothing is a 422 the student cannot act on.
    expect(screen.queryByRole("link", { name: /اختبرني/ })).toBeNull();
  });
});
