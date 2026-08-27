import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { MistakeFilterBar } from "./MistakeFilterBar";
import type { MistakeFilterOptions } from "@/lib/mistakes";

/*
| شريطُ تصفيةِ دفترِ الأخطاء.
|
| ⚠️ THE OPTIONS ARE THE SERVER'S, AND THAT IS THE WHOLE POINT. This component
| must never assemble a list of its own — spec 009 built a leaderboard picker
| from the nearest list to hand and offered boards the API answered `403` while
| hiding boards it allowed. What a component test CAN see is the half the server
| cannot: that a facet with nothing in it is not drawn at all, and that clearing
| does not quietly take the resolved toggle with it.
*/

function options(overrides: Partial<MistakeFilterOptions> = {}): MistakeFilterOptions {
  return {
    teachers: [
      { uuid: "w-1", label: "أ. سامي" },
      { uuid: "w-2", label: "أ. هدى" },
    ],
    courses: [{ uuid: "c-1", label: "الرياضيات" }],
    exams: [{ uuid: "e-1", label: "اختبار الوحدة" }],
    concepts: [],
    has_standing: true,
    ...overrides,
  };
}

describe("MistakeFilterBar", () => {
  it("offers exactly what the server sent, and nothing of its own", () => {
    render(<MistakeFilterBar options={options()} value={{}} onChange={vi.fn()} />);

    expect(screen.getByRole("combobox", { name: /المدرّس/ })).toBeTruthy();
    expect(screen.getByRole("option", { name: "أ. هدى" })).toBeTruthy();
    expect(screen.getByRole("option", { name: "الرياضيات" })).toBeTruthy();
  });

  /*
   | ⚠️ AN EMPTY FACET IS NOT DRAWN. A `<select>` labelled «الفكرة» with only its
   | placeholder in it is a question with no answers — which reads as a page that
   | failed to load, and costs a tap to discover.
  */
  it("draws no control for a facet the server sent nothing for", () => {
    render(<MistakeFilterBar options={options()} value={{}} onChange={vi.fn()} />);

    expect(screen.queryByRole("combobox", { name: /الفكرة/ })).toBeNull();
  });

  it("reports a choice by its uuid, and clearing it as undefined", () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <MistakeFilterBar options={options()} value={{}} onChange={onChange} />,
    );

    fireEvent.change(screen.getByRole("combobox", { name: /المدرّس/ }), {
      target: { value: "w-2" },
    });

    expect(onChange).toHaveBeenCalledWith({ teacher: "w-2" });

    rerender(<MistakeFilterBar options={options()} value={{ teacher: "w-2" }} onChange={onChange} />);

    fireEvent.change(screen.getByRole("combobox", { name: /المدرّس/ }), {
      target: { value: "" },
    });

    // `undefined`, not `""` — the client drops undefined keys from the query
    // string, and a `?teacher=` with nothing after it is a uuid that resolves
    // to nothing, which empties the notebook.
    expect(onChange).toHaveBeenLastCalledWith({ teacher: undefined });
  });

  /*
   | ⚠️ CLEARING KEEPS `include_resolved`. It is a view of the same list rather
   | than one of the narrowings — a reader who had switched to «الكل» and then
   | pressed «امسح التصفية» would otherwise be thrown back to «القائم» without
   | touching that control.
  */
  it("keeps the resolved toggle when the narrowing is cleared", () => {
    const onChange = vi.fn();

    render(
      <MistakeFilterBar
        options={options()}
        value={{ teacher: "w-1", include_resolved: true }}
        onChange={onChange}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "امسح التصفية" }));

    expect(onChange).toHaveBeenCalledWith({ include_resolved: true });
  });

  it("offers no clear button when nothing is narrowed", () => {
    render(
      <MistakeFilterBar options={options()} value={{ include_resolved: true }} onChange={vi.fn()} />,
    );

    expect(screen.queryByRole("button", { name: "امسح التصفية" })).toBeNull();
  });

  it("draws nothing at all before the options arrive", () => {
    const { container } = render(
      <MistakeFilterBar options={null} value={{}} onChange={vi.fn()} />,
    );

    expect(container.firstChild).toBeNull();
  });
});
