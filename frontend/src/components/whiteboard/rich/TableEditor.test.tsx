import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { blankTable } from "@/lib/whiteboard/table";
import { WB } from "@/lib/whiteboard/strings";

import { TableEditor } from "./TableEditor";

describe("TableEditor", () => {
  it("lays the cells right to left, first column on the right (US6-1)", () => {
    const { container } = render(<TableEditor initial={blankTable(2, 2)} onSave={vi.fn()} onClose={vi.fn()} />);
    expect(container.querySelector("table")?.getAttribute("dir")).toBe("rtl");
  });

  it("adds and removes rows and columns, fills a cell, and hands back the cells", () => {
    const onSave = vi.fn();
    render(<TableEditor initial={blankTable(2, 2)} onSave={onSave} onClose={vi.fn()} />);

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
});
