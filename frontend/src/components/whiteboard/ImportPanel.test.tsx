import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { WB } from "@/lib/whiteboard/strings";

import { ImportPanel } from "./ImportPanel";

describe("ImportPanel", () => {
  it("hands over the file chosen, and shows each stage of the import (US4-3)", () => {
    const onFile = vi.fn();
    const { rerender } = render(<ImportPanel view={{ phase: "idle" }} onFile={onFile} />);

    const input = screen.getByLabelText(WB.importing.choose) as HTMLInputElement;
    const pdf = new File(["%PDF"], "lesson.pdf", { type: "application/pdf" });
    fireEvent.change(input, { target: { files: [pdf] } });
    expect(onFile).toHaveBeenCalledWith(pdf);

    rerender(<ImportPanel view={{ phase: "converting", page: 3, total: 12 }} onFile={onFile} />);
    expect(screen.getByRole("status").textContent).toBe(WB.importing.converting(3, 12));
    expect(input.disabled).toBe(true); // one import at a time

    rerender(<ImportPanel view={{ phase: "done", pages: 5 }} onFile={onFile} />);
    expect(screen.getByRole("status").textContent).toBe(WB.importing.done(5));
    expect(input.disabled).toBe(false);

    rerender(<ImportPanel view={{ phase: "done", pages: 100, capped: 100 }} onFile={onFile} />);
    expect(screen.getByRole("status").textContent).toContain(WB.importing.capped(100));
  });

  it("says why it failed, in Arabic (US4-4)", () => {
    render(<ImportPanel view={{ phase: "failed", message: WB.errors.corrupt }} onFile={vi.fn()} />);
    expect(screen.getByRole("alert").textContent).toBe(WB.errors.corrupt);
  });
});
