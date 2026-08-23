import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { AssistantScopeForm } from "./AssistantScopeForm";
import type { AssistantAssignment, AssistantCourse } from "@/lib/assistants";

/*
| «NO CONFINEMENT» HAS TO BE A SENTENCE, NOT AN EMPTY LIST.
|
| No scope rows means EVERY course on the server. A screen that showed nothing
| ticked and said nothing else reads as «this person has been given no courses» —
| the opposite — and a teacher who believed it would tick one course meaning to
| widen the assistant's ground and would in fact narrow it from everything to one.
| Nothing on the server would refuse that; it is a correct request built on a
| misread screen, which is why the wording is what this file asserts.
*/

const COURSES: AssistantCourse[] = [
  { uuid: "course-a", title: "الرياضيات" },
  { uuid: "course-b", title: "الفيزياء" },
];

function assignment(overrides: Partial<AssistantAssignment> = {}): AssistantAssignment {
  return {
    uuid: "assignment-1",
    assistant: { uuid: "user-1", name: "سارة المصحّحة" },
    workspace: null,
    is_confined: false,
    courses: [],
    revoked_at: null,
    ...overrides,
  };
}

describe("AssistantScopeForm", () => {
  it("says in words that an unconfined assistant works on every course", () => {
    render(<AssistantScopeForm assignment={assignment()} courses={COURSES} onSave={vi.fn()} />);

    expect(screen.getByText("بلا تقييد")).toBeDefined();
    expect(screen.getByRole("checkbox", { name: "الرياضيات" })).toHaveProperty("checked", false);
  });

  it("starts from the courses the assistant already has", () => {
    render(
      <AssistantScopeForm
        assignment={assignment({ is_confined: true, courses: [COURSES[1]] })}
        courses={COURSES}
        onSave={vi.fn()}
      />,
    );

    expect(screen.getByRole("checkbox", { name: "الفيزياء" })).toHaveProperty("checked", true);
    expect(screen.getByRole("checkbox", { name: "الرياضيات" })).toHaveProperty("checked", false);
    // The confinement is real, so the «every course» notice must be gone — it
    // would be flatly false beside two ticked boxes.
    expect(screen.queryByText("بلا تقييد")).toBeNull();
  });

  it("submits the whole set, never a difference", async () => {
    const onSave = vi.fn();

    render(
      <AssistantScopeForm
        assignment={assignment({ is_confined: true, courses: [COURSES[0]] })}
        courses={COURSES}
        onSave={onSave}
      />,
    );

    await userEvent.click(screen.getByRole("checkbox", { name: "الفيزياء" }));
    await userEvent.click(screen.getByRole("button", { name: "حفظ النطاق" }));

    expect(onSave).toHaveBeenCalledWith(["course-a", "course-b"]);
  });

  it("submits an empty set to take a confinement off", async () => {
    const onSave = vi.fn();

    render(
      <AssistantScopeForm
        assignment={assignment({ is_confined: true, courses: [COURSES[0]] })}
        courses={COURSES}
        onSave={onSave}
      />,
    );

    await userEvent.click(screen.getByRole("checkbox", { name: "الرياضيات" }));

    // Unticking the last course brings the notice back BEFORE the save, so the
    // teacher is told what they are about to do rather than after.
    expect(screen.getByText("بلا تقييد")).toBeDefined();

    await userEvent.click(screen.getByRole("button", { name: "حفظ النطاق" }));

    expect(onSave).toHaveBeenCalledWith([]);
  });
});
