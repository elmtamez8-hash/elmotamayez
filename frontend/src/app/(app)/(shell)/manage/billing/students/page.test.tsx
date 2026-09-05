import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { StudentBalanceRow } from "@/lib/billing";

import StudentBalancesPage from "./page";

/**
 * ⛔ THE CREDIT CEILING MOVED ONLY BY ITSELF.
 *
 * `EvaluateCreditLimitsJob` and a recorded consent were its only writers
 * reachable from the product. `PATCH /manage/billing/students/{student}/limit`
 * existed, held a platform permission and was tested — and no file under
 * `frontend/src` called it, so a student demoted in error had no way back except
 * a hand-written row in the production database.
 *
 * ⚠️ THE CONTROL IS ABSENT FOR A TEACHER RATHER THAN DISABLED. Raising a ceiling
 * creates a debt the PLATFORM carries alone and the teacher is the party paid out
 * of it — so it is not a control they may have, and a disabled one invites the
 * question of how to enable it. The server refuses them either way; what this
 * decides is whether they are offered something that answers 403.
 */
const billing = vi.hoisted(() => ({ students: vi.fn(), setCreditLimit: vi.fn() }));
const auth = vi.hoisted(() => ({ user: { permissions: ["billing.limit.manage"] } }));

// ⚠️ The module exports `billing` AND `formatCredits`, and the page imports both.
// A factory returning the calls at the top level leaves `billing` undefined and
// every case fails inside the effect rather than on its own assertion.
vi.mock("@/lib/billing", () => ({
  billing,
  formatCredits: (n: number) => String(n),
}));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));

/*
 * ⚠️ THE LABELS ARE MATCHED BY REGEX, NOT BY AN EXACT STRING. `Field` appends a
 * ` *` marker to a required label — `aria-hidden`, so it is invisible to a
 * screen reader and still part of the label's text content — so an exact match
 * finds nothing and the failure reads as «the editor never opened».
 */

function row(overrides: Partial<StudentBalanceRow> = {}): StudentBalanceRow {
  return {
    student_uuid: "s-1",
    student_name: "سارة",
    course_uuid: "c-1",
    course_title: "التفاضل",
    remaining_credits: -3,
    purchased_credits: 8,
    consumed_credits: 11,
    credit_limit_credits: 0,
    is_withheld: true,
    ...overrides,
  };
}

describe("StudentBalancesPage", () => {
  beforeEach(() => {
    billing.students.mockReset();
    billing.setCreditLimit.mockReset();
    auth.user = { permissions: ["billing.limit.manage"] };
    billing.students.mockResolvedValue({ data: [row()] });
  });

  it("offers the ceiling editor to a platform officer", async () => {
    render(<StudentBalancesPage />);

    expect(await screen.findByRole("button", { name: "عدّل" })).toBeTruthy();
  });

  it("offers a teacher the number and no way to change it", async () => {
    auth.user = { permissions: ["billing.balance.view"] };

    render(<StudentBalancesPage />);

    // The row still renders in full — reading their own students' balances is
    // the teacher's screen; moving the platform's ceiling is not their decision.
    expect(await screen.findByText("سارة")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "عدّل" })).toBeNull();
  });

  it("sends the course with the ceiling, never the student alone", async () => {
    /*
     * ⚠️ PER BALANCE, AND THAT IS THE WHOLE POINT OF THE COURSE BEING IN THE
     * CALL. A ceiling on «the student» would be a ceiling at every teacher they
     * study with at once; the balance sits on the course precisely so a paid-up
     * course stays open while another is withheld.
     */
    billing.setCreditLimit.mockResolvedValue({ data: { credit_limit_credits: 4 } });

    render(<StudentBalancesPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.clear(screen.getByLabelText(/السقف بالحصص/));
    await userEvent.type(screen.getByLabelText(/السقف بالحصص/), "4");
    await userEvent.type(screen.getByLabelText(/السبب/), "سُوّي الدين خارج المنصّة");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() =>
      expect(billing.setCreditLimit).toHaveBeenCalledWith("s-1", {
        course: "c-1",
        credit_limit_credits: 4,
        reason: "سُوّي الدين خارج المنصّة",
      }),
    );
  });

  it("reloads from the server rather than trusting the number that was typed", async () => {
    // The Action caps the ceiling against a platform setting and may store less
    // than was asked for, and `is_withheld` is derived from five inputs the
    // server alone has seen. A local update would show a number nobody has.
    billing.setCreditLimit.mockResolvedValue({ data: { credit_limit_credits: 4 } });

    render(<StudentBalancesPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.type(screen.getByLabelText(/السبب/), "سبب مكتوب");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() => expect(billing.students).toHaveBeenCalledTimes(2));
  });

  it("never shows a raw error when the change is refused", async () => {
    billing.setCreditLimit.mockRejectedValue(new Error("Request failed with status 403"));

    render(<StudentBalancesPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.type(screen.getByLabelText(/السبب/), "سبب مكتوب");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 403/)).toBeNull();
  });
});
