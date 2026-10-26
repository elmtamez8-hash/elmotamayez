import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CourseFilters } from "./CourseFilters";

/*
| The courses page's filters (owner redesign, 2026-10-26) only rewrite the query
| string, so the URL each press produces is the behaviour.
*/

const push = vi.fn();
let query = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  usePathname: () => "/courses",
  useSearchParams: () => new URLSearchParams(query),
}));

const subjects = [{ slug: "math", name: "الرياضيات", teachers_count: 4 }];
const gradeLevels = [{ slug: "secondary", name: "المرحلة الثانوية" }];

const renderFilters = () => render(<CourseFilters subjects={subjects} gradeLevels={gradeLevels} />);

describe("CourseFilters", () => {
  beforeEach(() => {
    push.mockClear();
    query = "";
  });

  it("filters by a course-type chip, dropping the page, and a second press clears it", () => {
    query = "page=2";
    renderFilters();
    fireEvent.click(screen.getByRole("button", { name: "جماعي" }));
    expect(push).toHaveBeenLastCalledWith("/courses?type=group", { scroll: false });

    query = "type=group";
    renderFilters();
    fireEvent.click(screen.getAllByRole("button", { name: "جماعي", pressed: true })[0]);
    expect(push).toHaveBeenLastCalledWith("/courses", { scroll: false });
  });

  it("keeps the subject when the stage changes, since the course subjects are not scoped to it", () => {
    query = "subject=math";
    renderFilters();

    fireEvent.change(screen.getByLabelText("المرحلة الدراسية"), { target: { value: "secondary" } });

    expect(push).toHaveBeenLastCalledWith("/courses?subject=math&grade_level=secondary", { scroll: false });
  });

  it("counts the stage on the phone's sheet button", () => {
    query = "grade_level=secondary&type=group";
    renderFilters();

    expect(screen.getByRole("button", { name: /^فلاتر/ }).textContent).toContain("١");
  });
});
