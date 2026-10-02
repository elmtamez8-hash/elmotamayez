import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SaveIndicator } from "./SaveIndicator";

describe("SaveIndicator", () => {
  it("says «جارٍ الحفظ» while a save is travelling, and each state in its own words (US2-1)", () => {
    const { rerender } = render(<SaveIndicator state="saving" />);
    expect(screen.getByRole("status").textContent).toBe("جارٍ الحفظ…");

    const said = new Set<string>();
    for (const state of ["saved", "saving", "offline", "failed", "unprotected"] as const) {
      rerender(<SaveIndicator state={state} />);
      said.add(screen.getByRole("status").textContent ?? "");
    }
    expect(said.size).toBe(5);
  });
});
