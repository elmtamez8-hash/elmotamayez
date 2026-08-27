import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";

import { CheckIcon } from "@/components/icons";

import { AxisScore } from "./AxisScore";

afterEach(cleanup);

/** The filled dots, found by the token that fills them. */
function filledDots(container: HTMLElement): number {
  return container.querySelectorAll(".bg-primary").length;
}

describe("AxisScore", () => {
  it("fills one dot per point", () => {
    const { container } = render(<AxisScore label="الالتزام" value={3} Icon={CheckIcon} />);

    expect(filledDots(container)).toBe(3);
    expect(container.querySelectorAll(".bg-line").length).toBe(2);
  });

  /*
    ⚠️ THE CLAMP IS NOT DEFENSIVE PROGRAMMING, IT IS THE RENDER. `Array` maths on
    an out-of-range value produces a row with more marks than the scale has, and
    the reader then has no idea what «من ٥» means. The axis columns are validated
    1–5 on the server; this is what keeps a bad row from redefining the scale.
  */
  it("never draws more marks than the scale has", () => {
    const { container } = render(<AxisScore label="التحسّن" value={9} Icon={CheckIcon} />);

    expect(filledDots(container)).toBe(5);
  });

  it("draws no filled mark for a zero", () => {
    const { container } = render(<AxisScore label="الواجبات" value={0} Icon={CheckIcon} />);

    expect(filledDots(container)).toBe(0);
  });

  /*
    ⚠️ THE DIGIT STAYS. Five dots are read at a glance and are `aria-hidden`; a
    screen reader told only about decorative marks has been told nothing, which is
    why the number and the «من ٥» sentence both remain.
  */
  it("keeps the number readable and says the scale to a screen reader", () => {
    render(<AxisScore label="المشاركة" value={4} Icon={CheckIcon} />);

    // Arabic-Indic, as everywhere else in the product.
    expect(screen.getByText("٤")).toBeTruthy();
    expect(screen.getByText("المشاركة: ٤ من ٥")).toBeTruthy();
  });

  it("hides the dots from assistive technology", () => {
    const { container } = render(<AxisScore label="الالتزام" value={2} Icon={CheckIcon} />);

    expect(container.querySelector("[aria-hidden]")).toBeTruthy();
  });
});
