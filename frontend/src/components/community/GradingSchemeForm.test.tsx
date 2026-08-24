import { describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";

import { GradingSchemeForm } from "./GradingSchemeForm";

/*
| `SC-017` on the screen — zero weightings that do not add up to 100 leave here.
|
| ⚠️ THIS IS NOT THE GUARD AND THE TEST DOES NOT PRETEND IT IS. `SaveGradingScheme`
| enforces the rule in the Action, which is the entry point the seeders and the
| panel share with this form; `GradingSchemeTest` measures that. What is measured
| here is the thing no backend test can see: that the teacher is told WHICH way
| they are out before the round trip, rather than reading a bare refusal after
| submitting a form they thought was complete.
*/

describe("GradingSchemeForm", () => {
  const period = { start: "2026-08-01", end: "2026-08-31" };

  it("submits a weighting that reaches exactly 100", () => {
    const onSave = vi.fn();

    render(
      <GradingSchemeForm
        onSave={onSave}
        initialPeriod={period}
        initialWeights={{ exams: 60, homework: 30, attendance: 10, participation: 0 }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "حفظ الأوزان" }));

    expect(onSave).toHaveBeenCalledWith({
      period_start: "2026-08-01",
      period_end: "2026-08-31",
      weights: { exams: 60, homework: 30, attendance: 10, participation: 0 },
    });
  });

  it("refuses to submit a weighting below 100", () => {
    const onSave = vi.fn();

    render(
      <GradingSchemeForm
        onSave={onSave}
        initialPeriod={period}
        initialWeights={{ exams: 60, homework: 30, attendance: 5, participation: 0 }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "حفظ الأوزان" }));

    expect(onSave).not.toHaveBeenCalled();
  });

  it("names the gap rather than restating the rule", () => {
    render(
      <GradingSchemeForm
        onSave={vi.fn()}
        initialPeriod={period}
        initialWeights={{ exams: 60, homework: 30, attendance: 0, participation: 0 }}
      />,
    );

    // «ينقص ١٠٪» — actionable. Arabic-Indic, like every other number in the
    // product: a Latin «10» beside «٪» is the tell of a translated layout.
    expect(screen.getByText(/ينقص ١٠٪/)).toBeTruthy();
  });

  it("says so when the weighting overshoots", () => {
    render(
      <GradingSchemeForm
        onSave={vi.fn()}
        initialPeriod={period}
        initialWeights={{ exams: 60, homework: 30, attendance: 30, participation: 0 }}
      />,
    );

    expect(screen.getByText(/يزيد ٢٠٪/)).toBeTruthy();
  });

  it("recomputes the total as the teacher types", () => {
    render(
      <GradingSchemeForm
        onSave={vi.fn()}
        initialPeriod={period}
        initialWeights={{ exams: 50, homework: 25, attendance: 25, participation: 0 }}
      />,
    );

    expect(screen.getByTestId("weights-total").textContent).toContain("١٠٠");

    fireEvent.change(screen.getByLabelText("الاختبارات"), { target: { value: "40" } });

    expect(screen.getByTestId("weights-total").textContent).toContain("٩٠");
    expect(screen.getByRole("button", { name: "حفظ الأوزان" })).toHaveProperty("disabled", true);
  });

  it("allows a component weighted zero, which is not a component with no data", () => {
    const onSave = vi.fn();

    render(
      <GradingSchemeForm
        onSave={onSave}
        initialPeriod={period}
        initialWeights={{ exams: 100, homework: 0, attendance: 0, participation: 0 }}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "حفظ الأوزان" }));

    expect(onSave).toHaveBeenCalled();
  });
});
