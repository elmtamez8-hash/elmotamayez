import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { WB } from "@/lib/whiteboard/strings";

const calculate = vi.hoisted(() => vi.fn());
vi.mock("@/lib/whiteboard/calculator", () => ({ calculate, plainLatex: (latex: string) => latex }));

// MathLive as a plain element that records what the keys typed.
vi.mock("mathlive", () => {
  class MathfieldElement {
    static fontsDirectory = "";
    static soundsDirectory: string | null = "";
    constructor() {
      const el = document.createElement("div") as HTMLDivElement & { value: string; executeCommand: (c: unknown) => boolean; readOnly?: boolean };
      el.setAttribute("data-field", "");
      el.value = "";
      el.executeCommand = (command: unknown) => {
        if (Array.isArray(command) && command[0] === "insert") el.value += command[1] as string;
        if (command === "deleteBackward") el.value = el.value.slice(0, -1);
        return true;
      };
      return el as unknown as MathfieldElement;
    }
  }
  return { MathfieldElement };
});

import { Calculator } from "./Calculator";

const press = (label: string) => fireEvent.click(screen.getByRole("button", { name: label }));

describe("Calculator", () => {
  beforeEach(() => calculate.mockReset());

  it("types with its keys, works it out in degrees, and puts «question = answer» on the board", async () => {
    calculate.mockResolvedValue({ ok: true, exact: String.raw`\frac{1}{2}`, decimal: "0.5" });
    const onInsert = vi.fn();
    render(<Calculator onInsert={onInsert} onClose={() => undefined} />);
    await waitFor(() => expect(document.querySelectorAll("[data-field]")).toHaveLength(2));

    press("sin");
    press("3");
    press("0");
    press("=");

    await waitFor(() => expect(calculate).toHaveBeenCalledWith(String.raw`\sin\left(#0\right)30`, "deg"));
    fireEvent.click(screen.getByRole("button", { name: WB.calc.insert }));
    expect(onInsert).toHaveBeenCalledWith(String.raw`\sin\left(#0\right)30=\frac{1}{2}`);

    // S⇔D: the same answer as a decimal.
    press("S⇔D");
    fireEvent.click(screen.getByRole("button", { name: WB.calc.insert }));
    expect(onInsert).toHaveBeenLastCalledWith(String.raw`\sin\left(#0\right)30=0.5`);
  }, 15000);

  it("names the error like a Casio does, and switches to radians", async () => {
    calculate.mockResolvedValue({ ok: false, error: "math" });
    render(<Calculator onInsert={null} onClose={() => undefined} />);
    await waitFor(() => expect(document.querySelectorAll("[data-field]")).toHaveLength(2));

    press("DEG");
    press("1");
    press("=");

    expect((await screen.findByRole("alert")).textContent).toBe(WB.calc.mathError);
    expect(calculate).toHaveBeenCalledWith("1", "rad");
    // A board the teacher cannot edit gets no «put it on the board».
    expect(screen.queryByRole("button", { name: WB.calc.insert })).toBeNull();
  }, 15000);
});
