import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdaptiveRunner } from "./AdaptiveRunner";
import type { AdaptiveQuestion, AdaptiveSession, AdaptiveStep } from "@/lib/adaptive";

/*
| Spec 012 · T047. What the adaptive screen does, which no backend test can see.
|
| Three things are measured here and nowhere else: the selection REPLACES rather
| than accumulating (a second tap turned a right answer into a zero on the sibling
| runner, on a graded paper), the ceiling and the threshold are READ from the
| payload rather than assumed, and the walk between difficulties is ANNOUNCED —
| FR-004 is a sentence on a screen, and a field nobody renders is a student handed
| a difficulty they cannot account for.
|
| ⚠️ `userEvent`, NOT `fireEvent`, AND THAT IS SAFE ONLY BECAUSE THERE ARE NO FAKE
| TIMERS IN THIS FILE. `userEvent` awaits real timers between its simulated steps,
| so under `useFakeTimers` it hangs on a clock nothing advances and the test TIMES
| OUT rather than failing — the trap `ConfirmButton`'s test is written around.
| Adding a timer to this component means rewriting these with `fireEvent`.
*/

vi.mock("@/lib/adaptive", async () => {
  const actual = await vi.importActual<typeof import("@/lib/adaptive")>("@/lib/adaptive");

  return { ...actual, adaptive: { ...actual.adaptive, answer: vi.fn(), end: vi.fn() } };
});

const { adaptive } = await import("@/lib/adaptive");

/**
 * A concept whose questions stop at `medium` — the edge case the whole design
 * turns on. Measured literally at «hard» it could never be mastered.
 */
const session: AdaptiveSession = {
  uuid: "session-1",
  status: "running",
  status_label: "جارية",
  difficulty: "easy",
  ceiling_difficulty: "medium",
  correct_streak: 0,
  served_count: 1,
  max_questions: 20,
  mastery_after: 3,
  concept: { uuid: "concept-1", name: "المشتقّات" },
  mastered_at: null,
  ended_at: null,
  score: null,
};

const question: AdaptiveQuestion = {
  question_id: 512,
  order: 1,
  content: "ما مشتقّة س تربيع؟",
  points: 1,
  difficulty: "easy",
  options: [
    { id: 41, content: "الخيار الأوّل" },
    { id: 42, content: "الخيار الثاني" },
  ],
};

function optionButton(label: string): HTMLElement {
  return screen.getByRole("button", { name: new RegExp(label) });
}

function step(overrides: Partial<AdaptiveStep> = {}): AdaptiveStep {
  return {
    result: { is_correct: false, correct_option_ids: [42], explanation: "لأنّ القاعدة كذا." },
    session: { ...session, difficulty: "easy", served_count: 2 },
    difficulty_changed: false,
    difficulty_note: null,
    question: { ...question, question_id: 913, order: 2 },
    ...overrides,
  };
}

describe("AdaptiveRunner", () => {
  beforeEach(() => {
    vi.mocked(adaptive.answer).mockReset();
    vi.mocked(adaptive.end).mockReset();
  });

  it("replaces the selection instead of adding to it", async () => {
    const user = userEvent.setup();

    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(optionButton("الخيار الثاني"));

    expect(optionButton("الخيار الأوّل").getAttribute("aria-pressed")).toBe("false");
    expect(optionButton("الخيار الثاني").getAttribute("aria-pressed")).toBe("true");
  });

  it("clears the answer when the chosen option is tapped again", async () => {
    const user = userEvent.setup();

    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(optionButton("الخيار الأوّل"));

    expect(optionButton("الخيار الأوّل").getAttribute("aria-pressed")).toBe("false");
  });

  /*
   | ⚠️ THE CEILING IS READ, NOT DERIVED. A screen that hardcoded «صعب» would ask
   | this student for a level their concept does not have — the bar would be
   | unreachable by any action of theirs, which is the family of defect this
   | repository records as «an item that can never be completed».
   */
  it("states the mastery bar at the concept's own ceiling", () => {
    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    const bar = screen.getByText(/الإتقان/);

    expect(bar.textContent).toContain("3");
    expect(bar.textContent).toContain("متوسّط");
    expect(bar.textContent).not.toContain("صعب");
  });

  it("announces the walk to another difficulty rather than moving in silence", async () => {
    const user = userEvent.setup();

    vi.mocked(adaptive.answer).mockResolvedValue({
      data: step({
        difficulty_changed: true,
        difficulty_note: "لا أسئلة متاحة بهذه الصعوبة الآن، فانتقلنا إلى أقرب صعوبة في الفكرة نفسها.",
      }),
    });

    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(screen.getByRole("button", { name: "تأكيد الإجابة" }));

    expect(await screen.findByText(/أقرب صعوبة/)).toBeTruthy();
  });

  /*
   | Being told «خطأ» without being told what was right teaches nothing, which is
   | the entire point of marking instantly (FR-024).
   */
  it("shows the explanation once the answer is marked, and not before", async () => {
    const user = userEvent.setup();

    vi.mocked(adaptive.answer).mockResolvedValue({ data: step() });

    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    expect(screen.queryByText(/لأنّ القاعدة/)).toBeNull();

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(screen.getByRole("button", { name: "تأكيد الإجابة" }));

    expect(await screen.findByText(/لأنّ القاعدة/)).toBeTruthy();
  });

  it("says the concept was mastered and offers no next question", async () => {
    const user = userEvent.setup();

    vi.mocked(adaptive.answer).mockResolvedValue({
      data: step({
        result: { is_correct: true, correct_option_ids: [41], explanation: null },
        session: { ...session, status: "mastered", mastered_at: "2026-08-30T10:00:00Z", score: 80 },
        question: null,
      }),
    });

    render(<AdaptiveRunner start={{ session, question }} onFinish={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(screen.getByRole("button", { name: "تأكيد الإجابة" }));

    expect(await screen.findByText(/أتقنتَ هذه الفكرة/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "السؤال التالي" })).toBeNull();
  });
});
