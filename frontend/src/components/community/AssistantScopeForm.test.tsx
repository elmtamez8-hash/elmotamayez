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

  it("names each checkbox by its title and describes it by status and teacher", () => {
    render(
      <AssistantScopeForm
        assignment={assignment()}
        courses={[{ uuid: "c1", title: "الرياضيات", status: "draft", teacher: { name: "هدى" } }]}
        onSave={vi.fn()}
        showTeacher
      />,
    );

    const box = screen.getByRole("checkbox", { name: "الرياضيات" });
    const details = document.getElementById(box.getAttribute("aria-describedby") ?? "");

    expect(details?.textContent).toContain("مسودّة");
    expect(details?.textContent).toContain("هدى");
  });

  describe("with many courses", () => {
    const MANY: AssistantCourse[] = Array.from({ length: 8 }, (_, index) => ({
      uuid: `c${index}`,
      title: index === 3 ? "الكيمياء العضوية" : `كورس رقم ${index}`,
    }));

    it("offers no search box while the list fits at a glance", () => {
      render(<AssistantScopeForm assignment={assignment()} courses={COURSES} onSave={vi.fn()} />);

      expect(screen.queryByRole("searchbox")).toBeNull();
    });

    it("filters by title, and keeps a hidden tick in the set that is saved", async () => {
      const onSave = vi.fn();

      render(
        <AssistantScopeForm
          assignment={assignment({ is_confined: true, courses: [MANY[0]] })}
          courses={MANY}
          onSave={onSave}
        />,
      );

      await userEvent.type(screen.getByRole("searchbox", { name: "ابحث في الكورسات" }), "الكيمياء");

      expect(screen.getAllByRole("checkbox")).toHaveLength(1);

      await userEvent.click(screen.getByRole("checkbox", { name: "الكيمياء العضوية" }));
      await userEvent.click(screen.getByRole("button", { name: "حفظ النطاق" }));

      // c0 is filtered out of view and still in the set.
      expect(onSave).toHaveBeenCalledWith(["c0", "c3"]);
    });

    it("never saves on Enter in the search box", async () => {
      const onSave = vi.fn();

      render(<AssistantScopeForm assignment={assignment()} courses={MANY} onSave={onSave} />);

      await userEvent.type(screen.getByRole("searchbox"), "كورس{Enter}");

      expect(onSave).not.toHaveBeenCalled();
    });

    it("says so when nothing matches", async () => {
      render(<AssistantScopeForm assignment={assignment()} courses={MANY} onSave={vi.fn()} />);

      await userEvent.type(screen.getByRole("searchbox"), "تاريخ");

      expect(screen.getByText("لا كورس يطابق «تاريخ».")).toBeDefined();
    });
  });
});
