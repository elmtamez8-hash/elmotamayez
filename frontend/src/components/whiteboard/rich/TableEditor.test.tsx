import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { blankTable } from "@/lib/whiteboard/table";
import { WB } from "@/lib/whiteboard/strings";

import { TableEditor } from "./TableEditor";

describe("TableEditor", () => {
  it("lays the cells right to left, first column on the right (US6-1)", () => {
    const { container } = render(<TableEditor initial={blankTable(2, 2)} saving={false} onSave={vi.fn()} onClose={vi.fn()} />);
    expect(container.querySelector("table")?.getAttribute("dir")).toBe("rtl");
  });

  it("adds and removes rows and columns, fills a cell, and hands back the cells", () => {
    const onSave = vi.fn();
    render(<TableEditor initial={blankTable(2, 2)} saving={false} onSave={onSave} onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: WB.table.addRow }));
    fireEvent.click(screen.getByRole("button", { name: WB.table.addCol }));
    fireEvent.click(screen.getByRole("button", { name: WB.table.removeRow }));
    const first = screen.getByLabelText(WB.table.cell(1, 1));
    fireEvent.focus(first);
    fireEvent.change(first, { target: { value: "الزمن" } });
    fireEvent.click(screen.getByRole("button", { name: "#fff3b0" }));
    fireEvent.click(screen.getByRole("button", { name: WB.table.save }));

    const saved = onSave.mock.calls[0][0];
    expect(saved.rows).toHaveLength(2);
    expect(saved.colWidths).toHaveLength(3);
    expect(saved.rows[0].cells[0]).toEqual({ text: "الزمن", fill: "#fff3b0" });
  });

  it("merges the cells chosen with Shift + click into one, and splits it again", () => {
    const onSave = vi.fn();
    const { container } = render(<TableEditor initial={blankTable(2, 2)} saving={false} onSave={onSave} onClose={vi.fn()} />);
    expect(screen.getByRole("button", { name: WB.table.merge }).hasAttribute("disabled")).toBe(true);

    fireEvent.focus(screen.getByLabelText(WB.table.cell(1, 1)));
    fireEvent.mouseDown(screen.getByLabelText(WB.table.cell(1, 2)), { shiftKey: true });
    fireEvent.click(screen.getByRole("button", { name: WB.table.merge }));

    expect(container.querySelectorAll("td")).toHaveLength(3);
    expect(container.querySelector("td")?.getAttribute("colspan")).toBe("2");
    fireEvent.click(screen.getByRole("button", { name: WB.table.save }));
    expect(onSave.mock.calls[0][0].rows[0].cells[0].span).toEqual([1, 2]);

    // The merged cell focused alone is one cell: nothing to merge again.
    fireEvent.focus(screen.getByLabelText(WB.table.cell(2, 1)));
    fireEvent.focus(screen.getByLabelText(WB.table.cell(1, 1)));
    expect(screen.getByRole("button", { name: WB.table.merge }).hasAttribute("disabled")).toBe(true);

    fireEvent.click(screen.getByRole("button", { name: WB.table.split }));
    expect(container.querySelectorAll("td")).toHaveLength(4);
  });
});
