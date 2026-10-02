import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { ConflictDialog } from "./ConflictDialog";

// `fireEvent`, never `userEvent` — see Modal.test.tsx.
describe("ConflictDialog", () => {
  it("offers both choices on a conflict and each calls its own handler (US2-4)", () => {
    const onTakeServer = vi.fn();
    const onKeepMine = vi.fn();
    render(<ConflictDialog open onTakeServer={onTakeServer} onKeepMine={onKeepMine} onCancel={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: "احفظ نسختي كصفحة جديدة" }));
    expect(onKeepMine).toHaveBeenCalledTimes(1);
    expect(onTakeServer).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "خذ نسخة الخادم" }));
    expect(onTakeServer).toHaveBeenCalledTimes(1);
  });

  it("does not offer «a new page» before anything can make one", () => {
    render(<ConflictDialog open onTakeServer={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "احفظ نسختي كصفحة جديدة" })).toBeNull();
  });
});
