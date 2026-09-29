import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { StatStrip } from "./StatStrip";
import { StatTile } from "./StatTile";

describe("StatStrip", () => {
  it("groups the tiles under one name and staggers them in order", () => {
    render(
      <StatStrip label="ملخّص الشحنات">
        <StatTile label="قيد التجهيز" value={4} />
        <StatTile label="في الطريق" value={2} />
        <StatTile label="سُلّمت" value="—" />
      </StatStrip>,
    );

    const group = screen.getByRole("group", { name: "ملخّص الشحنات" });
    const cells = Array.from(group.children) as HTMLElement[];

    expect(cells).toHaveLength(3);
    cells.forEach((cell, index) => {
      expect(cell.className).toContain("stagger-item");
      expect(cell.style.getPropertyValue("--stagger-i")).toBe(String(index));
    });
  });

  it("two across on a phone whatever the desk width", () => {
    render(
      <StatStrip label="أرقام" columns={3}>
        <StatTile label="أ" value="١" />
      </StatStrip>,
    );

    const group = screen.getByRole("group", { name: "أرقام" });

    expect(group.className).toContain("grid-cols-2");
    expect(group.className).toContain("sm:grid-cols-3");
  });
});

describe("StatTile", () => {
  /*
  | ⚠️ jsdom has no `matchMedia` and no `IntersectionObserver`. A numeric value
  | goes through `AnimatedNumber`, whose FINAL value is its default — so the tile
  | must still show the real figure here, in Arabic digits, rather than throw.
  */
  it("shows a number in Arabic digits through AnimatedNumber", () => {
    render(<StatTile label="المنشورة" value={1325} />);

    const tile = screen.getByText("المنشورة").closest("div")!.parentElement!;

    expect(within(tile).getByText("١٬٣٢٥")).toBeTruthy();
  });

  it("shows a string exactly as given", () => {
    render(<StatTile label="المتوسط" value="٧٥٪" />);

    expect(screen.getByText("٧٥٪")).toBeTruthy();
  });
});
