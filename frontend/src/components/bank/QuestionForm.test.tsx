import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

/*
| EXACTLY ONE CORRECT OPTION, AND THE FORM USED TO OFFER CHECKBOXES.
|
| «At least one» reads as generous and is not: the grader compares the correct SET
| against the selected SET, so a question with two correct options can only be scored
| by selecting both — while every answering screen in this product takes ONE answer.
| Nobody can ever be right, and a wrong_pct of 100% reads as the hardest item in the
| bank, which is the number a teacher deletes a good question over.
|
| The server refuses it now, but a form that lets a teacher build the refusal and
| only tells them at save time is a form that wastes their work. The control is a
| real radio: exclusivity comes from the browser, and a screen reader is told
| «radio», not «checkbox» — which would mean "these toggle independently", the one
| thing that must not be said here.
*/

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

// The form asks for the concept list on mount. Mocked at the module rather than at
// `fetch`: what this test is about is a click, and a network stub would make it
// about wiring.
vi.mock("@/lib/bank", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/bank")>();

  return {
    ...actual,
    bank: {
      concepts: () => Promise.resolve({ data: [] }),
      create: vi.fn(),
      update: vi.fn(),
    },
  };
});

const { QuestionForm } = await import("./QuestionForm");

/** The «صحيح» marker beside option N (1-based), which is a radio in one group. */
function correctMarker(index: number): HTMLInputElement {
  return screen.getAllByRole("radio", { name: "صحيح" })[index - 1] as HTMLInputElement;
}

describe("QuestionForm", () => {
  it("moves the correct answer instead of adding a second one", async () => {
    const user = userEvent.setup();

    render(<QuestionForm />);

    // The form opens with two options and the first marked correct.
    expect(correctMarker(1).checked).toBe(true);
    expect(correctMarker(2).checked).toBe(false);

    await user.click(correctMarker(2));

    expect(correctMarker(1).checked).toBe(false);
    expect(correctMarker(2).checked).toBe(true);
  });

  /*
   | The exclusivity has to survive a third option arriving, because that is the
   | shape a real question is built in: type the stem, add choices, then mark one.
   */
  it("keeps one answer after another option is added", async () => {
    const user = userEvent.setup();

    render(<QuestionForm />);

    await user.click(screen.getByRole("button", { name: "أضف خياراً" }));
    await user.click(correctMarker(3));

    expect(screen.getAllByRole("radio", { name: "صحيح" })).toHaveLength(3);
    expect(correctMarker(1).checked).toBe(false);
    expect(correctMarker(2).checked).toBe(false);
    expect(correctMarker(3).checked).toBe(true);
  });
});
