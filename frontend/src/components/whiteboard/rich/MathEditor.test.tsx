import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { WB } from "@/lib/whiteboard/strings";

// MathLive is a custom element jsdom cannot run; the field is a plain stand-in.
vi.mock("mathlive", () => ({
  MathfieldElement: class extends HTMLElement {
    static fontsDirectory: string | null = null;
    static soundsDirectory: string | null = null;
    value = "";
  },
}));
if (!customElements.get("wb-test-math")) {
  // The class above must be registered before `new` is called on it.
  const { MathfieldElement } = await import("mathlive");
  customElements.define("wb-test-math", MathfieldElement as unknown as CustomElementConstructor);
}

import { MathEditor } from "./MathEditor";

describe("MathEditor", () => {
  it("previews the LaTeX with MathJax and inserts it; a typo cannot be inserted", async () => {
    const onSave = vi.fn();
    render(<MathEditor initial={{ kind: "math", v: 1, latex: "", display: true }} saving={false} onSave={onSave} onClose={vi.fn()} />);
    const save = screen.getByRole("button", { name: WB.math.save }) as HTMLButtonElement;
    expect(save.disabled).toBe(true);

    fireEvent.change(screen.getByRole("textbox"), { target: { value: String.raw`\notacommand` } });
    await waitFor(() => expect(screen.getByRole("alert").textContent).toBe(WB.math.invalid), { timeout: 15000 });
    expect(save.disabled).toBe(true);

    fireEvent.change(screen.getByRole("textbox"), { target: { value: String.raw`\ce{H2O}` } });
    await waitFor(() => expect(screen.getByRole("img")).toBeTruthy(), { timeout: 15000 });
    fireEvent.click(save);
    expect(onSave).toHaveBeenCalledWith({ kind: "math", v: 1, latex: String.raw`\ce{H2O}`, display: true });
  }, 30000);
});
