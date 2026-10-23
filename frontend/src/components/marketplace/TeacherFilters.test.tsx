import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TeacherFilters } from "./TeacherFilters";

/*
| The teachers page's filters (owner redesign, 2026-10-23): subject chips, two
| toggle chips, laptop selects and a phone sheet — all of them only rewrite the
| query string, so the URL each press produces is the behaviour.
*/

const push = vi.fn();
let query = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  usePathname: () => "/teachers",
  useSearchParams: () => new URLSearchParams(query),
}));

const subjects = [
  { slug: "math", name: "الرياضيات", teachers_count: 4 },
  { slug: "physics", name: "الفيزياء", teachers_count: 2 },
];
const gradeLevels = [{ slug: "secondary", name: "المرحلة الثانوية" }];

const renderFilters = () => render(<TeacherFilters subjects={subjects} gradeLevels={gradeLevels} />);

describe("TeacherFilters", () => {
  beforeEach(() => {
    push.mockClear();
    query = "";
  });

  it("filters by a subject chip, dropping the page, and a second press clears it", () => {
    query = "page=3";
    renderFilters();

    fireEvent.click(screen.getByRole("button", { name: "الرياضيات" }));
    expect(push).toHaveBeenLastCalledWith("/teachers?subject=math", { scroll: false });

    query = "subject=math";
    renderFilters();
    fireEvent.click(screen.getAllByRole("button", { name: "الرياضيات", pressed: true })[0]);
    expect(push).toHaveBeenLastCalledWith("/teachers", { scroll: false });
  });

  it("clears the subject when the stage changes, since the subject list depends on it", () => {
    query = "subject=math";
    renderFilters();

    fireEvent.change(screen.getByLabelText("المرحلة"), { target: { value: "secondary" } });

    expect(push).toHaveBeenLastCalledWith("/teachers?grade_level=secondary", { scroll: false });
  });

  it("toggles «متاح الآن» on and off as a chip", () => {
    renderFilters();
    fireEvent.click(screen.getByRole("button", { name: "متاح الآن" }));
    expect(push).toHaveBeenLastCalledWith("/teachers?available_now=1", { scroll: false });

    query = "available_now=1";
    renderFilters();
    fireEvent.click(screen.getAllByRole("button", { name: "متاح الآن", pressed: true })[0]);
    expect(push).toHaveBeenLastCalledWith("/teachers", { scroll: false });
  });

  it("counts the filters that live in the phone sheet on its button", () => {
    query = "grade_level=secondary&min_rating=4&subject=math";
    renderFilters();

    // Two of the three are sheet filters; the subject has its own chips.
    expect(screen.getByRole("button", { name: /^فلاتر/ }).textContent).toContain("٢");
  });
});
