import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { PracticeRunner } from "./PracticeRunner";
import type { PracticePaper } from "@/lib/practice";

/*
| ONE ANSWER PER QUESTION — AND IT USED TO ACCUMULATE.
|
| `GradeAttempt::matchesSnapshot()` compares the correct SET against the selected
| SET, and a question carries exactly one correct option. So a second tap turned a
| right answer into a wrong one, silently, with nothing on the screen saying a second
| tap was not allowed — and on the graded exam page there was no way back once the
| attempt was submitted.
|
| ⚠️ NO TEST IN THIS PRODUCT COULD SEE THAT. The backend suite grades the payload it
| is handed and is right to; what was wrong was which payload the screen built. This
| file is the first test of that layer.
*/

const paper: PracticePaper = {
  uuid: "paper-1",
  status: "in_progress",
  requested_count: 1,
  delivered_count: 1,
  duration_minutes: 10,
  questions: [
    {
      id: 1,
      type: "mcq",
      content: "أين تُخزَّن الهجرات؟",
      points: 1,
      options: [
        { id: 11, content: "الخيار الأوّل" },
        { id: 12, content: "الخيار الثاني" },
      ],
    },
  ],
};

/** The option buttons carry `aria-pressed`, which is also what a student sees as selected. */
function optionButton(label: string): HTMLElement {
  return screen.getByRole("button", { name: new RegExp(label) });
}

describe("PracticeRunner", () => {
  it("replaces the selection instead of adding to it", async () => {
    const user = userEvent.setup();

    render(<PracticeRunner paper={paper} onRestart={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(optionButton("الخيار الثاني"));

    expect(optionButton("الخيار الأوّل").getAttribute("aria-pressed")).toBe("false");
    expect(optionButton("الخيار الثاني").getAttribute("aria-pressed")).toBe("true");

    // And the count the student reads has to agree: two "selected" options on a
    // one-answer question would still say «أجبت عن ١».
    expect(screen.getByText(/أجبت عن/).textContent).toContain("1");
  });

  /*
   | Deselecting must stay reachable. An empty selection scores zero, which is what
   | an unanswered question IS — so a screen that cannot clear a choice takes away
   | the student's ability to leave one blank on purpose.
   */
  it("clears the answer when the chosen option is tapped again", async () => {
    const user = userEvent.setup();

    render(<PracticeRunner paper={paper} onRestart={() => undefined} />);

    await user.click(optionButton("الخيار الأوّل"));
    await user.click(optionButton("الخيار الأوّل"));

    expect(optionButton("الخيار الأوّل").getAttribute("aria-pressed")).toBe("false");
    expect(screen.getByText(/أجبت عن/).textContent).toContain("0");
  });
});
