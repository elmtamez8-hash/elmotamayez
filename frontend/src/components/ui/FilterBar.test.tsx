import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { FilterBar, type FilterOption } from "./FilterBar";

/*
| `fireEvent`, not `userEvent` — the kit's rule since `ConfirmButton`: the latter
| awaits real timers between its steps, and nothing here needs them.
*/

const OPTIONS: FilterOption[] = [
  { key: "all", label: "الكل", count: 12 },
  { key: "published", label: "المنشورة", count: 3 },
  { key: "draft", label: "المسودّات" },
];

function Harness({ onFilter = () => undefined }: { onFilter?: (key: string) => void }) {
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");

  return (
    <FilterBar
      search={{ id: "q", label: "ابحث في المقالات", value: query, onChange: setQuery, placeholder: "عنوان المقال" }}
      filters={{
        label: "حالة المقال",
        options: OPTIONS,
        value: filter,
        onChange: (key) => {
          setFilter(key);
          onFilter(key);
        },
      }}
      summary={`يظهر: ${query === "" ? "الكل" : query}`}
    />
  );
}

describe("FilterBar", () => {
  it("names the search box by its label, not by its placeholder", () => {
    render(<Harness />);

    const box = screen.getByRole("searchbox", { name: "ابحث في المقالات" });

    expect(box.getAttribute("placeholder")).toBe("عنوان المقال");
  });

  it("offers a clear button only once something is typed, and it clears and refocuses", () => {
    render(<Harness />);

    const box = screen.getByRole("searchbox") as HTMLInputElement;

    expect(screen.queryByRole("button", { name: "مسح البحث" })).toBeNull();

    fireEvent.change(box, { target: { value: "الكسور" } });
    expect(box.value).toBe("الكسور");

    const clear = screen.getByRole("button", { name: "مسح البحث" });

    expect(clear.getAttribute("type")).toBe("button");

    fireEvent.click(clear);

    expect(box.value).toBe("");
    expect(document.activeElement).toBe(box);
    expect(screen.queryByRole("button", { name: "مسح البحث" })).toBeNull();
  });

  it("presses exactly one chip, in a named group", () => {
    const onFilter = vi.fn();

    render(<Harness onFilter={onFilter} />);

    const group = screen.getByRole("group", { name: "حالة المقال" });
    const pressed = () =>
      Array.from(group.querySelectorAll("button[aria-pressed='true']")).map((b) => b.textContent);

    expect(pressed()).toEqual(["الكل ١٢"]);

    fireEvent.click(screen.getByRole("button", { name: /المسودّات/ }));

    expect(onFilter).toHaveBeenCalledWith("draft");
    expect(pressed()).toEqual(["المسودّات"]);
  });

  it("keeps a real space before the count, so the name does not read «المنشورة٣»", () => {
    render(<Harness />);

    expect(screen.getByRole("button", { name: "المنشورة ٣" })).toBeTruthy();
  });

  it("chips are buttons that never submit a surrounding form", () => {
    render(<Harness />);

    for (const chip of screen.getByRole("group").querySelectorAll("button")) {
      expect(chip.getAttribute("type")).toBe("button");
    }
  });

  it("announces the summary politely as it changes", () => {
    render(<Harness />);

    const summary = screen.getByText("يظهر: الكل");

    expect(summary.getAttribute("aria-live")).toBe("polite");

    fireEvent.change(screen.getByRole("searchbox"), { target: { value: "جبر" } });

    expect(screen.getByText("يظهر: جبر")).toBe(summary);
  });

  it("renders only the parts it is given", () => {
    render(
      <FilterBar
        filters={{ label: "الحالة", options: OPTIONS, value: "all", onChange: () => undefined }}
      />,
    );

    expect(screen.queryByRole("searchbox")).toBeNull();
    expect(screen.getByRole("group", { name: "الحالة" })).toBeTruthy();
  });
});
