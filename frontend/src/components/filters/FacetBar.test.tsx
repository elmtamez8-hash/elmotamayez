import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { FacetBar, type Facet } from "./FacetBar";

afterEach(cleanup);

const TEACHERS: Facet = {
  key: "teacher",
  label: "المدرّس",
  placeholder: "كلّ المدرّسين",
  options: [
    { uuid: "t-1", label: "أ. خالد" },
    { uuid: "t-2", label: "أ. منى" },
  ],
};

describe("FacetBar", () => {
  /*
    ⚠️ THE CASE THIS COMPONENT EXISTS FOR. `/practice` filled its only filter
    from a teacher-only endpoint that answers a student `403`, so the control
    rendered for every student on the platform with nothing in it — a question
    with no answers, which reads as a page that failed to load.
  */
  it("does not draw a facet with no options", () => {
    render(
      <FacetBar
        facets={[{ key: "concept", label: "الفكرة", placeholder: "كلّ الأفكار", options: [] }]}
        values={{}}
        onChange={() => undefined}
        onReset={() => undefined}
      />,
    );

    expect(screen.queryByLabelText("الفكرة")).toBeNull();
  });

  /*
    ⚠️ AND ONE OPTION IS STILL DRAWN — the opposite rule shipped first and deleted
    the whole bar on ordinary data. A student with one teacher and one course saw
    no filters at all and asked where they had gone; the controls were correct and
    invisible. Only NOTHING is hidden.
  */
  it("draws a facet that has a single option", () => {
    render(
      <FacetBar
        facets={[{ ...TEACHERS, options: [{ uuid: "t-1", label: "أ. خالد" }] }]}
        values={{}}
        onChange={() => undefined}
        onReset={() => undefined}
      />,
    );

    expect(screen.getByLabelText("المدرّس")).toBeTruthy();
  });

  it("renders nothing at all when no facet is usable", () => {
    const { container } = render(
      <FacetBar
        facets={[{ key: "course", label: "المادّة", placeholder: "كلّ المواد", options: [] }]}
        values={{}}
        onChange={() => undefined}
        onReset={() => undefined}
      />,
    );

    expect(container.firstChild).toBeNull();
  });

  it("reports the chosen value under its own key", () => {
    const onChange = vi.fn();

    render(
      <FacetBar facets={[TEACHERS]} values={{}} onChange={onChange} onReset={() => undefined} />,
    );

    fireEvent.change(screen.getByLabelText("المدرّس"), { target: { value: "t-2" } });

    expect(onChange).toHaveBeenCalledWith("teacher", "t-2");
  });

  /*
    ⚠️ «امسح التصفية» APPEARS ONLY ONCE SOMETHING IS NARROWED. A permanent reset
    beside an untouched bar is a button that does nothing.
  */
  it("offers the reset only while a filter is active", () => {
    const { rerender } = render(
      <FacetBar facets={[TEACHERS]} values={{}} onChange={() => undefined} onReset={() => undefined} />,
    );

    expect(screen.queryByRole("button", { name: "امسح التصفية" })).toBeNull();

    rerender(
      <FacetBar
        facets={[TEACHERS]}
        values={{ teacher: "t-1" }}
        onChange={() => undefined}
        onReset={() => undefined}
      />,
    );

    expect(screen.getByRole("button", { name: "امسح التصفية" })).toBeTruthy();
  });

  it("keeps the «all of them» row so a narrowed list can be widened again", () => {
    render(
      <FacetBar
        facets={[TEACHERS]}
        values={{ teacher: "t-1" }}
        onChange={() => undefined}
        onReset={() => undefined}
      />,
    );

    const select = screen.getByLabelText("المدرّس") as HTMLSelectElement;

    expect(Array.from(select.options).map((option) => option.value)).toContain("");
  });
});
